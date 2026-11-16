<?php

declare(strict_types=1);

namespace Drupal\trotamundos_brand\Controller;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the brand's editorial guidance and its actual design tokens.
 */
final class BrandGuideController extends ControllerBase {

  public function __construct(
    private readonly ThemeExtensionList $themeList,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('extension.list.theme'), $container->get('theme.manager'));
  }

  /**
   * Builds the guide directly from the theme's two canonical source files.
   */
  public function guide(): array {
    $theme_name = $this->themeManager->getActiveTheme()->getName();
    $relative_path = $this->themeList->getPath($theme_name);
    if (!is_file(DRUPAL_ROOT . '/' . $relative_path . '/brand.yml') || !is_file(DRUPAL_ROOT . '/' . $relative_path . '/css/tokens.css')) {
      $theme_name = 'trotamundos';
      $relative_path = $this->themeList->getPath($theme_name);
    }
    $theme_path = DRUPAL_ROOT . '/' . $relative_path;
    $brand_source = $this->readSource($theme_path . '/brand.yml');
    try {
      $brand = Yaml::decode($brand_source);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('No se ha podido leer la guía de marca: brand.yml no contiene YAML válido.', 0, $exception);
    }
    if (!is_array($brand)) {
      throw new \RuntimeException('La guía de marca requiere un objeto YAML en brand.yml.');
    }

    // These declarations live in one controlled :root block in tokens.css.
    $css = preg_replace('~/\*.*?\*/~s', '', $this->readSource($theme_path . '/css/tokens.css'));
    preg_match_all('/--([a-z][a-z0-9-]*)\s*:\s*([^;{}]+);/i', $css, $matches, PREG_SET_ORDER);
    $tokens = [];
    foreach ($matches as $match) {
      $tokens[$match[1]] = trim($match[2]);
    }
    if (!$tokens) {
      throw new \RuntimeException('La guía de marca requiere declaraciones de variables CSS en css/tokens.css.');
    }

    foreach (['colors', 'contrast_pairs', 'type_scale', 'spacing'] as $section) {
      if (!isset($brand[$section]) || !is_array($brand[$section])) {
        throw new \RuntimeException(sprintf('Falta la lista "%s" en brand.yml.', $section));
      }
    }

    foreach ($brand['colors'] as &$color) {
      $this->requireFields($color, ['key', 'foreground'], 'colors');
      $color['token'] = '--' . $color['key'];
      $color['hex'] = $this->hexToken($tokens, $color['key']);
      $color['rgb'] = implode(', ', $this->rgb($color['hex']));
      $color['foreground_hex'] = $this->hexToken($tokens, $color['foreground']);
    }
    unset($color);

    foreach ($brand['contrast_pairs'] as &$pair) {
      $this->requireFields($pair, ['foreground', 'background'], 'contrast_pairs');
      $pair['foreground_hex'] = $this->hexToken($tokens, $pair['foreground']);
      $pair['background_hex'] = $this->hexToken($tokens, $pair['background']);
      $foreground = $this->luminance($pair['foreground_hex']);
      $background = $this->luminance($pair['background_hex']);
      $ratio = (max($foreground, $background) + 0.05) / (min($foreground, $background) + 0.05);
      // WCAG thresholds apply to the full ratio, before display rounding.
      $pair['ratio'] = round($ratio, 2);
      $pair['normal'] = $ratio >= 4.5;
      $pair['large'] = $ratio >= 3;
      $pair['result'] = $pair['normal'] ? 'Texto normal AA' : ($pair['large'] ? 'Solo texto grande AA' : 'Uso decorativo');
    }
    unset($pair);

    foreach ($brand['type_scale'] as &$size) {
      $this->requireFields($size, ['key'], 'type_scale');
      $size['value'] = $this->token($tokens, $size['key']);
    }
    unset($size);

    $spacing = [];
    foreach ($brand['spacing'] as $key) {
      if (!is_string($key)) {
        throw new \RuntimeException('Cada entrada de spacing en brand.yml debe ser el nombre de una variable CSS.');
      }
      $value = $this->token($tokens, $key);
      if (!preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $pixel_match)) {
        throw new \RuntimeException(sprintf('La variable de espacio --%s debe estar expresada en píxeles.', $key));
      }
      $spacing[] = ['token' => '--' . $key, 'value' => $value, 'pixels' => 0 + $pixel_match[1]];
    }
    $brand['spacing'] = $spacing;
    $brand['tokens'] = $tokens;
    $brand['theme_name'] = $theme_name;
    $brand['theme_path'] = base_path() . $relative_path;
    $brand['photo_credits'] = json_decode(
      $this->readSource($theme_path . '/assets/image-sources.json'),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );

    return [
      '#theme' => 'trotamundos_brand_guide',
      '#brand' => $brand,
      '#cache' => ['max-age' => 0, 'contexts' => ['theme']],
      '#attached' => [
        'library' => [$theme_name . '/brand-guide'],
        'html_head' => [[[
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'description',
            'content' => 'La identidad de Trotamundos: colores, tipografías, firma, fotografía, tono de voz y componentes de una marca que invita a descubrir Colombia junto a sus comunidades.',
          ],
        ], 'trotamundos_brand_description']],
      ],
    ];
  }

  /**
   * Reads a source file, reporting a useful error if it is missing.
   */
  private function readSource(string $path): string {
    if (!is_readable($path)) {
      throw new \RuntimeException(sprintf('No se puede leer el archivo de identidad: %s.', $path));
    }
    $source = file_get_contents($path);
    if ($source === FALSE) {
      throw new \RuntimeException(sprintf('No se ha podido cargar el archivo de identidad: %s.', $path));
    }
    return $source;
  }

  /**
   * Requires the keys needed to resolve an entry against the stylesheet.
   */
  private function requireFields(mixed $entry, array $fields, string $section): void {
    foreach ($fields as $field) {
      if (!is_array($entry) || !isset($entry[$field]) || !is_string($entry[$field])) {
        throw new \RuntimeException(sprintf('Cada entrada de %s en brand.yml debe incluir "%s" como texto.', $section, $field));
      }
    }
  }

  /**
   * Resolves a canonical custom property without silently adding a fallback.
   */
  private function token(array $tokens, string $key): string {
    if (!isset($tokens[$key])) {
      throw new \RuntimeException(sprintf('La variable --%s no está definida en css/tokens.css.', $key));
    }
    return $tokens[$key];
  }

  /**
   * Ensures displayed colors and contrast calculations use valid RGB HEX.
   */
  private function hexToken(array $tokens, string $key): string {
    $value = $this->token($tokens, $key);
    if (!preg_match('/^#[0-9a-f]{6}$/i', $value)) {
      throw new \RuntimeException(sprintf('La variable de color --%s debe ser un HEX de seis cifras.', $key));
    }
    return strtoupper($value);
  }

  /**
   * Splits a validated hexadecimal color into its three RGB channels.
   */
  private function rgb(string $hex): array {
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
  }

  /**
   * Calculates WCAG 2.2 relative luminance for an sRGB color.
   */
  private function luminance(string $hex): float {
    $linear = array_map(static function (int $channel): float {
      $srgb = $channel / 255;
      return $srgb <= 0.04045 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
    }, $this->rgb($hex));
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
  }

}
