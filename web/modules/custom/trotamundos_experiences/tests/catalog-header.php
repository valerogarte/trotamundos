<?php

declare(strict_types=1);

/**
 * @file
 * Checks the catalog header and navigation without changing content.
 *
 * Run with drush php:script after provisioning the catalog.
 */

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\trotamundos_experiences\Setup\ProductTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) {
  throw new RuntimeException('Run this test with drush php:script.');
}

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};

$assert_wordmark = static function (DOMXPath $xpath, string $path) use ($assert): void {
  $logo = $xpath->query('//*[@data-site-header]//a[contains(concat(" ", normalize-space(@class), " "), " wordmark ")]')->item(0);
  $assert($logo instanceof DOMElement && $logo->getAttribute('href') === '/' && $logo->getAttribute('aria-label') === 'Trotamundos, inicio' && preg_replace('/\s+/u', '', $logo->textContent) === 'trotamundos.', "The header logo on $path must link home without the attribution.");
};

$assert_legal_links = static function (DOMXPath $xpath, string $path) use ($assert): void {
  foreach ([
    '/politica-de-privacidad' => 'Política de privacidad',
    '/aviso-legal' => 'Aviso legal',
    '/politica-de-cookies' => 'Política de cookies',
  ] as $url => $label) {
    $links = $xpath->query('//footer//a[@href="' . $url . '"]');
    $assert($links->length === 1 && trim($links->item(0)->textContent) === $label, "The footer on $path must link to $label.");
  }
  $privacy = $xpath->query('//*[@data-discovery-assistant]//a[contains(concat(" ", normalize-space(@class), " "), " trotamundos-assistant__privacy-link ")]');
  $assert($privacy->length === 1 && $privacy->item(0)->getAttribute('href') === '/politica-de-privacidad' && trim($privacy->item(0)->textContent) === 'Política de privacidad', "The assistant on $path must link directly to the privacy policy.");
  $assert($xpath->query('//*[@data-discovery-assistant]//p[contains(concat(" ", normalize-space(@class), " "), " trotamundos-assistant__privacy-notice ") and not(ancestor::noscript)]')->length === 0, "The assistant on $path must not show the old privacy notice.");
};

$repository = \Drupal::service('trotamundos_experiences.repository');
$destinations = $repository->destinationIndex();
$styles = $repository->filterOptions([])['styles'];
$assert($destinations !== [] && $styles !== [], 'The test requires a published destination and a travel style.');
$destination = reset($destinations);
$today = new DateTimeImmutable($repository->today());
$search = [
  'destino' => $destination['department_slug'],
  'desde' => $today->modify('+35 days')->format('Y-m-d'),
  'hasta' => $today->modify('+38 days')->format('Y-m-d'),
  'margen' => '3',
  'acompanamiento' => 'pareja',
];
$style = $styles[0]['slug'];
$query = $search + [
  'estilo' => $style,
  'precio_min' => '125000',
  'precio_max' => '275000',
  'orden' => 'precio-asc',
];

foreach (['/alojamientos', '/experiencias', '/paquetes', '/planes'] as $path) {
  drupal_static_reset('_trotamundos_site_data');
  $request = Request::create($path, 'GET', $query);
  $response = \Drupal::service('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
  $assert($response->getStatusCode() === 200, "The catalog $path did not respond successfully.");

  $document = new DOMDocument();
  $assert($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), "The catalog $path did not render HTML.");
  $xpath = new DOMXPath($document);
  $assert_wordmark($xpath, $path);
  $assert_legal_links($xpath, $path);
  $result_heading = $xpath->query('//*[@data-catalog-results and @aria-labelledby="catalog-results-title"]/*[@id="catalog-results-title" and contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")]')->item(0);
  $assert($result_heading !== NULL && trim($result_heading->textContent) !== '', "The result count on $path must remain available to screen readers without being displayed.");
  $assert($xpath->query('//*[@id="discovery-form" and @method="get"]')->length === 1, "The catalog $path lost its GET search form.");
  $assert($xpath->query('//*[@id="discovery-companions"]//button[@data-discovery-close and @aria-label="Cerrar acompañamiento"]')->length === 1, "The companion panel on $path must have an accessible close button.");
  if (\Drupal::currentUser()->isAnonymous()) {
    $assert($xpath->query('//*[@id="trotamundos-navigation"]//a[contains(@href, "/user/logout")]')->length === 0, "The anonymous menu on $path must not offer logout.");
  }
  $assert($xpath->query('//*[@data-discovery-assistant]//textarea[@id="discovery-assistant-message"]')->length === 1, "The catalog $path must include the search assistant.");
  $composer = $xpath->query('//*[@data-discovery-assistant]//*[contains(concat(" ", normalize-space(@class), " "), " trotamundos-assistant__composer ")]')->item(0);
  $assert($composer !== NULL && $xpath->query('./textarea[@id="discovery-assistant-message"]', $composer)->length === 1, "The assistant on $path must keep its input inside the composer.");
  $submit = $xpath->query('./button[@type="submit" and @aria-label="Enviar" and @disabled]', $composer)->item(0);
  $assert($submit !== NULL && trim($submit->textContent) === '' && $xpath->query('./svg[@aria-hidden="true"]', $submit)->length === 1, "The assistant on $path must provide an accessible icon-only send button.");
  $assert($xpath->query('//*[@data-discovery-assistant]//*[@data-assistant-search-result]')->length === 1, "The assistant results on $path must stay inside the search.");

  $assert($xpath->query('//*[@data-discovery-controls]/details/summary')->length === 2, "The catalog $path must expose filters and the assistant.");
  $assert($xpath->query('//*[@data-header-discovery]//*[@data-catalog-filters]')->length === 1, "The catalog $path must keep its filters in the shared search.");
  foreach (['estilo' => $style, 'orden' => 'precio-asc'] as $name => $value) {
    $assert($xpath->query('//*[@data-catalog-filters]//input[@type="radio" and @name="' . $name . '" and @value="' . $value . '" and @checked and @form="discovery-form"]')->length === 1, "The $name selection was lost on $path.");
  }
  foreach (['precio_min' => '125000', 'precio_max' => '275000'] as $name => $value) {
    $assert($xpath->query('//*[@data-catalog-filters]//input[@name="' . $name . '" and @value="' . $value . '" and @form="discovery-form"]')->length === 1, "The $name selection was lost on $path.");
  }
  $assert($xpath->query('//*[@data-catalog-filters]//*[@data-price-count]')->length === 24, "The catalog $path must render the price distribution.");
  $assert($xpath->query('//*[@data-catalog-filters]//*[@name="duracion"]')->length === 0, "The catalog $path must not offer duration as a filter.");
  $assert($xpath->query('//*[@data-catalog-filters]//button[@type="submit" and @form="discovery-form"]')->length === 1, "The catalog $path must remain usable without JavaScript.");
  $assert($xpath->query('//*[@data-catalog-filters]//input[@name="tipo"]')->length === ($path === '/planes' ? 4 : 0), "The catalog $path has an incorrect plan type control.");

  $links = $xpath->query('//*[@data-discovery-navigation]');
  $assert($links->length === 6, "The catalog $path must provide desktop and menu category links.");
  foreach ($links as $link) {
    parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $actual);
    $assert($actual == $search, "A category link on $path did not preserve exactly the shared search.");
  }
}

$card = [
  'title' => 'Un plan especial',
  'url' => '/planes/ejemplo',
  'style_slugs' => '',
  'favorite' => NULL,
  'image' => NULL,
  'type' => NULL,
  'location' => '',
  'summary' => 'Una razón <cercana> & especial para elegirlo.',
  'duration' => '',
  'styles' => [],
  'price_label' => '$123.000',
];
$render_card = static function (array $card): DOMXPath {
  $html = \Drupal::service('twig')->render('@trotamundos/components/experience-card.html.twig', ['card' => $card]);
  $document = new DOMDocument();
  if (!$document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
    throw new RuntimeException('The experience card did not render HTML.');
  }
  return new DOMXPath($document);
};
$card_xpath = $render_card($card);
$summary = $card_xpath->query('//*[contains(@class, "experience-card__media")]/p[contains(@class, "experience-card__summary") and @aria-hidden="true"]')->item(0);
$assert($summary !== NULL && $summary->textContent === $card['summary'], 'The card must display the escaped editorial summary over its image.');
$assert($card_xpath->query('.//span/*', $summary)->length === 0, 'The editorial summary must be plain text.');
$accessible_summary = $card_xpath->query('//*[contains(@class, "experience-card__title")]/following-sibling::*[1][contains(@class, "visually-hidden")]')->item(0);
$assert($accessible_summary !== NULL && $accessible_summary->textContent === $card['summary'], 'The complete summary must follow the title for screen readers.');
$assert($card_xpath->query('//*[contains(@class, "experience-card__media--empty")]')->length === 1, 'A card without an image must retain its media area.');
$price = $card_xpath->query('//*[contains(@class, "experience-card__price")]')->item(0);
$assert($price !== NULL && $card_xpath->query('./*', $price)->length === 2, 'The card price must contain only the from label and amount.');
$assert($card_xpath->query('./span', $price)->length === 1 && $card_xpath->query('./strong[text()="$123.000"]', $price)->length === 1, 'The card price lost its amount or included an extra currency label.');
$assert(preg_replace('/\s+/u', ' ', trim($price->textContent)) === 'Desde $123.000', 'The card price must read exactly "Desde" followed by the amount.');
$demo_card = $card;
$demo_card['is_example'] = TRUE;
$demo_card['type'] = ['label' => 'Alojamiento'];
$assert($render_card($demo_card)->query('//*[contains(concat(" ", normalize-space(@class), " "), " experience-card__type ")]')->length === 0, 'Cards must not display type or DEMO badges.');
$card['summary'] = '   ';
$assert($render_card($card)->query('//*[contains(@class, "experience-card__summary")]')->length === 0, 'A blank summary must not leave an empty card description.');
unset($card['summary']);
$assert($render_card($card)->query('//*[contains(@class, "experience-card__summary")]')->length === 0, 'A missing summary must not leave an empty card description.');

drupal_static_reset('_trotamundos_site_data');
$fragment_request = Request::create('/planes', 'GET', [
  'estilo' => $style,
  '_trotamundos_catalog_fragment' => '1',
]);
$fragment_response = \Drupal::service('http_kernel')->handle($fragment_request, HttpKernelInterface::SUB_REQUEST);
$assert($fragment_response->getStatusCode() === 200, 'The asynchronous catalog request failed.');
$assert(str_contains((string) $fragment_response->headers->get('Cache-Control'), 'no-store'), 'The asynchronous catalog response must not be cached.');
$fragment = new DOMDocument();
$assert($fragment->loadHTML($fragment_response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'The asynchronous catalog did not render HTML.');
$fragment_xpath = new DOMXPath($fragment);
$assert($fragment_xpath->query('//*[@data-catalog-results]')->length === 1, 'The asynchronous catalog lost its results.');
$assert($fragment_xpath->query('//*[@data-catalog-results]//*[contains(concat(" ", normalize-space(@class), " "), " catalog-results__notice ")]')->length === 0, 'The catalog must not display the example notice.');
$assert($fragment_xpath->query('//*[@data-catalog-filters]//input[@name="estilo" and @value="' . $style . '" and @checked]')->length === 1, 'The asynchronous catalog lost its filter selection.');
$assert($fragment_xpath->query('//*[@data-catalog-results]//*[@data-big-pipe-placeholder-id or self::drupal-render-placeholder]')->length === 0, 'The asynchronous catalog must resolve private placeholders.');
$assert($fragment_xpath->query('//*[@data-catalog-results]//a[contains(@href, "_trotamundos_catalog_fragment")]')->length === 0, 'Internal catalog parameters leaked into result links.');
$language_links = $fragment_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " language-switch ")]//a[@hreflang]');
$assert($language_links->length > 0, 'The asynchronous catalog lost its language links.');
foreach ($language_links as $link) {
  parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $language_query);
  $assert(($language_query['estilo'] ?? '') === $style && !isset($language_query['_trotamundos_catalog_fragment']), 'A language link lost the current filter or included the internal catalog parameter.');
}

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new UserSession(['uid' => 1]));
try {
  drupal_static_reset('_trotamundos_site_data');
  $request = Request::create('/planes', 'GET', ['_trotamundos_catalog_fragment' => '1']);
  $response = \Drupal::service('http_kernel')->handle($request, HttpKernelInterface::SUB_REQUEST);
  $document = new DOMDocument();
  $assert($response->getStatusCode() === 200 && $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'The authenticated catalog fragment did not render.');
  $xpath = new DOMXPath($document);
  $logout = $xpath->query('//*[@id="trotamundos-navigation"]//a[contains(@href, "/user/logout")]');
  $assert($logout->length === 1, 'The authenticated menu must contain a logout link.');
  $assert($xpath->query('./preceding-sibling::a[1][contains(@href, "/user")]', $logout->item(0))->length === 1, 'Logout must follow the account link.');
  $assert($xpath->query('.//*[contains(@class, "icon--logout")]/*', $logout->item(0))->length > 0, 'Logout must retain its menu icon.');
  $logout_url = $logout->item(0)->getAttribute('href');
  parse_str((string) parse_url($logout_url, PHP_URL_QUERY), $logout_query);
  $assert(isset($logout_query['token']) && \Drupal::service('csrf_token')->validate($logout_query['token'], 'user/logout'), 'The logout link must carry a valid session-specific CSRF token.');
  $settings_node = $xpath->query('//script[@data-drupal-selector="drupal-settings-json"]')->item(0);
  $assert($settings_node !== NULL, 'The authenticated catalog fragment lost Drupal settings.');
  $settings = json_decode($settings_node->textContent, TRUE, 512, JSON_THROW_ON_ERROR);
  $favorites = $xpath->query('//*[@data-catalog-results]//form[contains(@class, "favorite-plan-form--compact")]');
  $assert($favorites->length > 0, 'The authenticated catalog fragment lost its favorite forms.');
  foreach ($favorites as $favorite) {
    $button = $xpath->query('.//input[@type="submit"]', $favorite)->item(0);
    $assert($button !== NULL && isset($settings['ajax'][$button->getAttribute('id')]), 'A favorite form lost its Drupal AJAX settings.');
    $assert($xpath->query('.//input[@name="form_token"]', $favorite)->length === 1, 'A favorite form lost its CSRF token.');
  }
  $assert($xpath->query('//*[@data-catalog-results]//*[@data-big-pipe-placeholder-id or self::drupal-render-placeholder]')->length === 0, 'The authenticated catalog fragment left private placeholders unresolved.');
  $assert(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'), 'The authenticated catalog fragment must not be cached.');
}
finally {
  $switcher->switchBack();
  drupal_static_reset('_trotamundos_site_data');
}

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$price_field = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_tm_catalog_price')
  ? 'field_tm_catalog_price' : 'field_precio';
$base_query = static fn () => $node_storage->getQuery()
  ->accessCheck(TRUE)
  ->condition('type', ProductTypes::BUNDLES, 'IN')
  ->condition('status', 1)
  ->condition('default_langcode', 1);
foreach ([
  ['precio_min' => '150000', 'precio_max' => '300000'],
  ['precio_min' => '600000'],
  ['precio_max' => '0'],
] as $price_filter) {
  $expected = $base_query();
  if (isset($price_filter['precio_min'])) {
    $expected->condition($price_field, (int) $price_filter['precio_min'], '>=');
  }
  if (isset($price_filter['precio_max'])) {
    $expected->condition($price_field, (int) $price_filter['precio_max'], '<=');
  }
  $filters = $repository->filtersFromRequest(Request::create('/planes', 'GET', $price_filter));
  $assert($repository->search($filters)['total'] === (int) $expected->count()->execute(), 'The price range did not filter the catalog inclusively.');
}

$scoped = $repository->filtersFromRequest(Request::create('/planes', 'GET', [
  'tipo' => 'experiencia',
  'estilo' => $style,
  'precio_min' => '125000',
  'precio_max' => '275000',
  'orden' => 'precio-asc',
]));
$distribution = $repository->priceDistribution($scoped);
$expected = $base_query()->condition('type', 'experiencia')
  ->condition('field_estilo', $styles[0]['id'])
  ->condition($price_field, 0, '>=')
  ->count()->execute();
$assert($distribution['count'] === (int) $expected, 'The price distribution must respect the other filters but ignore the current price range.');
$assert(array_sum(array_column($distribution['bars'], 'count')) === $distribution['count'], 'The histogram lost catalog prices.');
$active = $repository->activeFilters($scoped, $repository->filterOptions($scoped));
$price_active = array_values(array_filter($active, static fn (array $item): bool => $item['key'] === 'precio'));
$assert(count($price_active) === 1, 'Both price bounds must form one removable filter.');
parse_str((string) parse_url($price_active[0]['remove_url'], PHP_URL_QUERY), $remaining);
$assert(!isset($remaining['precio_min']) && !isset($remaining['precio_max']) && ($remaining['estilo'] ?? '') === $style && ($remaining['orden'] ?? '') === 'precio-asc', 'Removing a price range lost another filter.');

$legacy = $repository->filtersFromRequest(Request::create('/planes', 'GET', ['precio' => 'hasta-300000', 'duracion' => 'una-noche']));
$legacy_distribution = $repository->priceDistribution($legacy);
$assert($legacy['precio'] === 'hasta-300000' && $legacy['duracion'] === 'una-noche' && $legacy_distribution['minimum'] === '' && $legacy_distribution['maximum'] === '300000', 'Legacy price and duration links must remain usable.');
$new_range = $repository->filtersFromRequest(Request::create('/planes', 'GET', ['precio' => 'hasta-300000', 'precio_min' => '600000']));
$assert($new_range['precio'] === '' && $new_range['precio_min'] === '600000', 'An explicit price range must replace the legacy interval.');
foreach ([
  ['precio_min' => '-1'],
  ['precio_max' => '1e5'],
  ['precio_min' => '2', 'precio_max' => '1'],
  ['precio_max' => '1000000000000000'],
] as $invalid) {
  try {
    $repository->filtersFromRequest(Request::create('/planes', 'GET', $invalid));
    throw new RuntimeException('An invalid price range was accepted.');
  }
  catch (BadRequestHttpException) {
  }
}

foreach (['/', '/destinos', '/ayuda', '/home-ai'] as $path) {
  drupal_static_reset('_trotamundos_site_data');
  $response = \Drupal::service('http_kernel')->handle(Request::create($path), HttpKernelInterface::SUB_REQUEST);
  $assert($response->getStatusCode() === 200, "The page $path did not respond successfully.");
  $document = new DOMDocument();
  $assert($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), "The page $path did not render HTML.");
  $xpath = new DOMXPath($document);
  $assert_wordmark($xpath, $path);
  $assert_legal_links($xpath, $path);
  $assert($xpath->query('//*[@data-header-discovery]//*[@data-discovery-assistant]//textarea[@id="discovery-assistant-message" and @disabled]')->length === 1, "The page $path must have a search assistant with a safe no-JavaScript fallback.");
  $assert($xpath->query('//*[@data-discovery-assistant]//noscript')->length === 1, "The assistant on $path must explain its JavaScript requirement.");
  if ($path === '/') {
    $assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " home-experiences__note ")]')->length === 0, 'The home page must not display the example notice.');
  }
  if ($path === '/home-ai') {
    $assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " trotamundos-assistant ")]')->length === 1, 'The assistant page must not add a second floating chat.');
  }
}

foreach ([
  '/politica-de-privacidad' => 'Política de privacidad',
  '/aviso-legal' => 'Aviso legal',
  '/politica-de-cookies' => 'Política de cookies',
] as $path => $title) {
  drupal_static_reset('_trotamundos_site_data');
  $response = \Drupal::service('http_kernel')->handle(Request::create($path), HttpKernelInterface::SUB_REQUEST);
  $document = new DOMDocument();
  $assert($response->getStatusCode() === 200 && $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), "The legal page $path did not render.");
  $xpath = new DOMXPath($document);
  $headings = $xpath->query('//main//h1');
  $assert($headings->length === 1 && trim($headings->item(0)->textContent) === $title, "The legal page $path must have its own heading.");
  $content = $xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " legal-page ")]')->item(0);
  $assert($content !== NULL && str_contains($content->textContent, 'Contenido provisional, pendiente de revisión.'), "The legal page $path must identify its content as provisional.");
  if ($path === '/politica-de-privacidad') {
    $assert(str_contains($content->textContent, 'No incluyas datos personales, confidenciales o de pago.'), 'The privacy page must retain the assistant safety advice.');
  }
  $assert_legal_links($xpath, $path);
}

$front_variables = ['is_front' => TRUE, 'page' => []];
trotamundos_preprocess_page($front_variables);
$front_variables['tm_styles'] = [['slug' => 'estilo-nuevo', 'label' => 'Estilo nuevo', 'icon' => 'leaf', 'count' => 0]];
$front_variables['tm_featured'] = [];
$front_variables['tm_destinations'] = [];
$front_html = \Drupal::service('renderer')->executeInRenderContext(new RenderContext(), static fn () =>
  \Drupal::service('twig')->render('@trotamundos/layout/page--front.html.twig', $front_variables)
);
$front_document = new DOMDocument();
$assert($front_document->loadHTML($front_html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'The home template did not render.');
$front_xpath = new DOMXPath($front_document);
$fallback_image = $front_xpath->query('//*[@id="travel-styles-track"]//a[contains(@href, "estilo=estilo-nuevo")]/img')->item(0);
$fallback_path = $fallback_image instanceof DOMElement ? parse_url($fallback_image->getAttribute('src'), PHP_URL_PATH) : NULL;
$assert(is_string($fallback_path) && is_file(DRUPAL_ROOT . $fallback_path), 'A new travel style must have an available fallback image.');

$example_ids = $node_storage->getQuery()->accessCheck(TRUE)
  ->condition('type', ProductTypes::BUNDLES, 'IN')
  ->condition('status', 1)
  ->condition('field_ejemplo', 1)
  ->exists('field_commerce_product')
  ->range(0, 1)
  ->execute();
$assert($example_ids !== [], 'The test requires an example listing with a linked product.');
$example_response = \Drupal::service('http_kernel')->handle(Request::create('/node/' . reset($example_ids)), HttpKernelInterface::SUB_REQUEST);
$example_document = new DOMDocument();
$assert($example_response->getStatusCode() === 200 && $example_document->loadHTML($example_response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'The example listing did not render.');
$example_xpath = new DOMXPath($example_document);
$assert($example_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " example-badge ")]')->length === 0, 'The listing must not display the example notice.');
$purchase_notice = $example_xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " commerce-purchase ")]//*[contains(concat(" ", normalize-space(@class), " "), " tm-notice ")]')->item(0);
$assert($purchase_notice !== NULL && trim($purchase_notice->textContent) === 'El carrito no confirma una reserva ni disponibilidad.', 'The cart must keep its booking and availability warning.');

echo "Catalog header and shared search checks passed.\n";
