#!/usr/bin/env php
<?php
// phpcs:ignoreFile

/**
 * @file
 * Populate a composer.json with the right drupal/core-recommended and other dependencies.
 */

$project_name = $argv[1] ?? getenv('CI_PROJECT_NAME');
if (empty($project_name)) {
  throw new RuntimeException('Unable to determine project name.');
}

$path = 'composer.json';
$json_project = json_decode(file_get_contents($path), TRUE);
$json_default = default_json($project_name);

// Avoid adding core-recommended if drupal/core is present.
if (isset($json_project['require-dev']['drupal/core'])) {
  unset($json_default['require-dev']['drupal/core-recommended']);
}

// Conditionally add prophecy.
if (!isset($json_project['require-dev']['phpspec/prophecy-phpunit']) && version_compare(getenv('_TARGET_CORE'), '9.0.0', '>=')) {
  $json_default['require-dev']['phpspec/prophecy-phpunit'] = '^2';
}

// Do not add "packages.drupal.org" twice if it is added by the module.
// This can happen if a module wants to use a fork and exclude some
// canonical projects via the "exclude" section in favor of their forks.
if (!empty($json_project['repositories']) && is_array($json_project['repositories'])) {
  $packages_drupal_org_found = FALSE;
  foreach ($json_project['repositories'] as $repository) {
    if (isset($repository['url']) && $repository['url'] == 'https://packages.drupal.org/8') {
      $packages_drupal_org_found = TRUE;
    }
  }
  if ($packages_drupal_org_found) {
    unset($json_default['repositories']['drupal']);
  }
}

// Merge the default and the project composer.json.
$json_rich = merge_deep($json_default, $json_project);

// The order of the 'repositories' entry values is important, so prioritize the
// module's values first, if defined.
$json_rich['repositories'] = merge_deep($json_project['repositories'] ?? [], $json_default['repositories'] ?? []);

// Add allowed modules for the lenient plugin if defined.
$lenient_allow_list = getenv('LENIENT_ALLOW_LIST');
if ($lenient_allow_list) {
  if (!isset($json_rich['extra']['drupal-lenient']['allowed-list'])) {
    $json_rich['extra']['drupal-lenient']['allowed-list'] = [];
  }
  foreach (explode(',', $lenient_allow_list) as $module_name) {
    $json_rich['extra']['drupal-lenient']['allowed-list'][] = 'drupal/' . trim($module_name);
  }
}

// Add a composer patch file if defined.
$composer_patches_file = getenv('COMPOSER_PATCHES_FILE');
if ($composer_patches_file) {
  if (is_file($composer_patches_file) && file_exists($composer_patches_file)) {
    $json_rich['extra']['patches-file'] = $composer_patches_file;
  }
  elseif (is_file('./.gitlab-ci/' . $composer_patches_file) && file_exists('./.gitlab-ci/' . $composer_patches_file)) {
    $json_rich['extra']['patches-file'] = './.gitlab-ci/' . $composer_patches_file;
  }
  elseif (parse_url($composer_patches_file, PHP_URL_SCHEME)) {
    $json_rich['extra']['patches-file'] = $composer_patches_file;
  }
  else {
    print "Patch file is not valid!";
    exit(1);
  }
}

// Remove empty top-level items.
$json_rich = array_filter($json_rich);
file_put_contents(empty(getenv('COMPOSER')) ? $path : getenv('COMPOSER'), json_encode($json_rich, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));

/**
 * Get default composer.json contents.
 */
function default_json(string $project_name): array {
  $drupalConstraint = getenv('_TARGET_CORE') ?: '^9';
  $webRoot = getenv('_WEB_ROOT') ?: 'web';
  return [
    'name' => 'drupal/' . $project_name,
    'type' => 'drupal-module',
    'description' => 'A description',
    'license' => 'GPL-2.0-or-later',
    'repositories' => [
      'drupal' => [
        'type' => 'composer',
        'url' => 'https://packages.drupal.org/8',
      ],
    ],
    'require' => [],
    'require-dev' => [
      'composer/installers' => '^1 || ^2',
      'cweagans/composer-patches' => '~1.0',
      'drupal/core-composer-scaffold' => $drupalConstraint,
      'drupal/core-dev' => $drupalConstraint,
      'drupal/core-recommended' => $drupalConstraint,
      'drupal/devel_php' => '^1.6',
      'drush/drush' => '^10 || ^11 || ^12',
      'php-parallel-lint/php-parallel-lint' => '^1.2',
    ],
    'minimum-stability' => 'dev',
    'prefer-stable' => TRUE,
    'config' => [
      'process-timeout' => 36000,
      "allow-plugins" => [
        "dealerdirect/phpcodesniffer-composer-installer" => TRUE,
        "composer/installers" => TRUE,
        "cweagans/composer-patches" => TRUE,
        "drupal/core-composer-scaffold" => TRUE,
        "drupalspoons/composer-plugin" => TRUE,
        "phpstan/extension-installer" => TRUE,
      ],
    ],
    'extra' => [
      'installer-paths' => [
        $webRoot . '/core' => [
          0 => 'type:drupal-core',
        ],
        $webRoot . '/libraries/{$name}' => [
          0 => 'type:drupal-library',
        ],
        $webRoot . '/modules/contrib/{$name}' => [
          0 => 'type:drupal-module',
        ],
        $webRoot . '/profiles/{$name}' => [
          0 => 'type:drupal-profile',
        ],
        $webRoot . '/themes/{$name}' => [
          0 => 'type:drupal-theme',
        ],
        'drush/{$name}' => [
          0 => 'type:drupal-drush',
        ],
      ],
      'drupal-scaffold' => [
        'locations' => [
          'web-root' => $webRoot . '/',
        ],
      ],
      'drush' => [
        'services' => [
          'drush.services.yml' => '^9 || ^10 || ^11',
        ],
      ],
    ],
  ];
}

/**
 * Deeply merges arrays. Borrowed from Drupal core.
 */
function merge_deep(): array {
  return merge_deep_array(func_get_args());
}

/**
 * Deeply merges arrays. Borrowed from drupal.org/project/core.
 *
 * @param array $arrays
 *   An array of array that will be merged.
 * @param bool $preserve_integer_keys
 *   Whether to preserve integer keys.
 */
function merge_deep_array(array $arrays, bool $preserve_integer_keys = FALSE): array {
  $result = [];
  foreach ($arrays as $array) {
    foreach ($array as $key => $value) {
      if (is_int($key) && !$preserve_integer_keys) {
        $result[] = $value;
      }
      elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
        $result[$key] = merge_deep_array([$result[$key], $value], $preserve_integer_keys);
      }
      else {
        $result[$key] = $value;
      }
    }
  }
  return $result;
}
