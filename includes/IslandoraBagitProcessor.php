<?php

/* 
 * This is the general class for handling the bagit for islandora repository
 */

abstract class IslandoraBagitProcessor
{
    const ISLANDORA_CONTENT_MODELS = array(
                                        "book"=>"islandora:bookCModel", 
                                        "newspaper"=>"islandora:newspaperCModel", 
                                        "newspaper_issue"=>"islandora:newspaperIssueCModel"
                                    );
    var $uri;
    var $parent_collection;
    var $pid_namespace;
    var $recipe_data;
    var $tmp_dir;
    
    function __construct($uri, $parent_collection, $pid_namespace, $recipe_data)
    {
        $this->uri = $uri;
        $this->parent_collection = $parent_collection;
        $this->pid_namespace = $pid_namespace;
        $this->recipe_data = $recipe_data;
    }
    
    function getUri(){
        return $this->uri;
    }
    
    function getParentCollection(){
        return $this->parent_collection;
    }
    
    function getPidNamespace(){
        return $this->pid_namespace;
    }
    
    function getRecipeData(){
        return $this->recipe_data;
    }
    
    function setRecipeData($recipe_data){
        $this->recipe_data = $recipe_data;
    }
    
    function getTmpDir(){
        return $this->tmp_dir;
    }
    
    function setTmpDir($tmp_dir){
        $this->tmp_dir = $tmp_dir;
    }
            
    function prepareImportDataFromBagit(){
        $file_data = drupal_json_decode(file_get_contents($this->uri));
        if (!$file_data) {
          drupal_set_message(t("Could not download recipe."));
          return;
        }
        $this->setRecipeData($file_data);
        $directory_basename = $file_data['recipe']['uuid'];
        $directory = drush_get_option('tmp_dir', "temporary://ou_bagit_importer/{$directory_basename}");
        if (file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
            $this->setTmpDir($directory);
        }

//        $pathinfo = pathinfo($uri);
//        $json_filename = $pathinfo['basename'];
//        if (file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
//          drupal_set_message(t("TMP files can be found (and removed manually) at %directory", array('%directory' => $directory)));
//          @copy($uri, "{$directory}/{$json_filename}");
//          batchImportFromRecipeData($pathinfo['dirname'], $directory, $json_filename, $parent_collection, $pid_namespace);
//        }
//        else {
//          drupal_set_message(t("Could not create directory. You may need to run drush command as sudo"), 'error');
//        }
    }
    
    function buildObjectModel($object_info) {
        $content_types = self::ISLANDORA_CONTENT_MODELS;
        $type = $content_types[$object_info['import']];
        $metadata = $object_info['metadata'];
        $object = array(
            'type' => $type,
            'pid_namespace' => $this->pid_namespace,
            'uuid' => $object_info['uuid'],
            'label' => $object_info['label'],
            'metadata' => $metadata,
        );
        return $object;
    }
    
    function outputResults($result, $overwrite = FALSE){
        $directory = $this->tmp_dir;
        $results_file_path = "{$directory}/results.json"; 
        if ($overwrite && file_exists($results_file_path)) {
            $results = (array) drupal_json_decode(file_get_contents($results_file_path));
        }
        $results[$result['object']] = $result;
        file_put_contents($results_file_path, drupal_json_encode($results));
    }
    
    abstract protected function batchImportFromRecipeData($source_uri, $drupal_directory, $json_filename, $parent_collection, $pid_namespace);

    abstract public function updateIslandoraCollection();
}