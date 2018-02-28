<?php

/**
 * @file
 */

/**
 * Implements hook_drush_command().
 */
function ou_bagit_importer_drush_command() {
  $items = array();

  $items['ou_bagit_importer_import_ou_bag'] = array(
    'callback' => 'drush_ou_bagit_importer_import_ou_bag',
    'aliases' => array('oubib'),
    'description' => 'Import an OU bag from a stream',
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
    'options' => array(
      'recipe_uri' => array(
        'description' => 'Path to recipe file (can be local or remote)',
        'required' => TRUE,
      ),
      'parent_collection' => array(
        'description' => 'Parent collection recipe will be imported into',
        'required' => TRUE,
      ),
      'pid_namespace' => array(
        'description' => 'Namespace to be used for PID creation',
        'required' => TRUE,
      ),
      'tmp_dir' => array(
        'description' => 'Path to tmp downloaded files (will be in public files directory by default)',
        'required' => FALSE,
      ),
    ),
  );

  return $items;
}

/**
 *
 */
function drush_ou_bagit_importer_import_ou_bag() {
  $uri = drush_get_option('recipe_uri');
  $parent_collection = drush_get_option('parent_collection');
  $pid_namespace = drush_get_option('pid_namespace');

  $file_data = drupal_json_decode(file_get_contents($uri));
  if (!$file_data) {
    drupal_set_message(t("Could not download recipe."));
  }
  print_r($file_data);
//  $directory_basename = $file_data['recipe']['uuid'];
//  $directory = drush_get_option('tmp_dir', "temporary://ou_bagit_importer/{$directory_basename}");
//
//  $pathinfo = pathinfo($uri);
//  $json_filename = $pathinfo['basename'];
//  if (file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
//    drupal_set_message(t("TMP files can be found (and removed manually) at %directory", array('%directory' => $directory)));
//    @copy($uri, "{$directory}/{$json_filename}");
//    ou_bagit_importer_process_json_file($pathinfo['dirname'], $directory, $json_filename, $parent_collection, $pid_namespace);
//  }
//  else {
//    drupal_set_message(t("Could not create directory. You may need to run drush command as sudo"), 'error');
//  }
}

/**
 *
 */
function ou_bagit_importer_process_json_file($source_uri, $drupal_directory, $json_filename, $parent_collection, $pid_namespace) {
  $json_data = drupal_json_decode(file_get_contents("{$drupal_directory}/{$json_filename}"));
  if ($json_data) {
    $type = $json_data['recipe']['import'];
    $metadata = array();
    foreach ($json_data['recipe']['metadata'] as $datastream => $path) {
      $metadata[$datastream] = _ou_bagit_importer_copy($source_uri, $drupal_directory, $path);
    }
    if ($json_data['recipe']['import'] == 'book') {
      $object = array(
        'type' => 'islandora:bookCModel',
        'pid_namespace' => $pid_namespace,
        'uuid' => $json_data['recipe']['uuid'],
        'label' => $json_data['recipe']['label'],
        'metadata' => $metadata,
      );
      $result = ou_bagit_importer_save_book_collection($object, $json_data['recipe'], $parent_collection, $pid_namespace);
      _ou_bagit_importer_write_results("{$drupal_directory}/results.json", $result, TRUE);
      drupal_set_message(t('Saving book collection @book', array('@book' => $object['uuid'])));
      ou_bagit_importer_process_json_book_pages($source_uri, $drupal_directory, $object, $json_data['recipe'], $pid_namespace );
    }

    $batch =& batch_get();
    $batch['progressive'] = FALSE;
    drush_backend_batch_process();
  }
  else {
    drupal_set_message(t('Invalid JSON data!'), 'error');
  }
}

/**
 *
 */
function ou_bagit_importer_process_json_book_pages($source_uri, $drupal_directory, $object, $json_data, $pid_namespace) {
  $batch = array(
    'operations' => array(),
    'finished' => '_ou_bagit_importer_batches_finished',
    'title' => dt('Ingesting BAG...'),
    'init_message' => dt('Preparing to ingest BAG...'),
    'progress_message' => dt('Ingesting BAG'),
    'error_message' => dt('File could not get ingested'),
  );
  foreach ($json_data['pages'] as $key => $page) {
    $batch['operations'][] = array(
      'ou_bagit_importer_batch_import_page',
      array(
        $source_uri,
        $drupal_directory,
        $object,
        $key,
        $page,
        $json_data,
        $pid_namespace,
      ),
    );
  }
  batch_set($batch);
}

/**
 *
 */
function ou_bagit_importer_batch_import_page($source_uri, $drupal_directory, $object, $page_number, $page, $json_data, $pid_namespace, &$context ) {
  $result = array();
  $object = array(
    'pid_namespace' => $pid_namespace,
    'source_uri' => $source_uri,
    'drupal_directory' => $drupal_directory,
    'file' => $page['file'],
    'hash' => $page['md5'],
    'exif' => $page['exif'],
    'type' => 'islandora:pageCModel',
    'book' => $json_data['uuid'],
    'page_number' => $page_number,
    'uuid' => $page['uuid'],
    'label' => $page['label'],
    'metadata' => $metadata,
  );
  drupal_set_message(t("Page @page will get ingested here", array('@page' => $file)));
  $result = ou_bagit_importer_save_book_page($object, $json_data);

  if (!empty($result)) {
    _ou_bagit_importer_write_results("{$drupal_directory}/results.json", $result);
  }
}

/**
 *
 */
function _ou_bagit_importer_batches_finished($success, $results, $operations) {
  drush_print('Ingest complete!');
}

/**
 * Checks if the path to bag is remote or local.
 */
function ou_bagit_importer_is_path_remote($path) {
  $host = parse_url($path, PHP_URL_HOST);
  if ($host === NULL) {
    return FALSE;
  }
  else {
    return TRUE;
  }
}

/**
 * Writes result to file.
 */
function _ou_bagit_importer_write_results($filename, $result, $overwrite = FALSE) {
  $results = array();
  if ($overwrite && file_exists($filename)) {
    $results = (array) drupal_json_decode(file_get_contents($filename));
  }
  $results[$result['object']] = $result;
  file_put_contents($filename, drupal_json_encode($results));
}
