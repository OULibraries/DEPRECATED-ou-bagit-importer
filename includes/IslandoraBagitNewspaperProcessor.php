<?php

/* 
 * This class is specific for newspaper module
 */

class IslandoraBagitNewspaperProcessor extends IslandoraBagitProcessor {
    
    function __construct()
    {
        parent::__construct($uri, $parent_collection, $pid_namespace);
    }
    
    function batchImportFromRecipeData(){
        $this->prepareImportDataFromBagit();
        $result = $this->updateIslandoraCollection();
        $this->outputResults($result);
        drupal_set_message(t('Saving book collection @book', array('@book' => $object['uuid'])));
    }
    
    function updateIslandoraCollection() 
    {
        // Reset because we want to make sure tuque is connecting with the right credentials
        $parent_collection = $this->parent_collection;
        $object_array = $this->buildObjectModel($this->recipe_data['recipe']);
        $is_update = $this->recipe_data['recipe']['update'];
        drupal_static_reset('islandora_get_tuque_connection');
        $tuque = islandora_get_tuque_connection();
        $uuid = "{$this->pid_namespace}".":{$object_array['uuid']}";
        $islandora_object = islandora_object_load($uuid);
        $content_models = array(
          $object_array['type'],
          'fedora-system:FedoraObject-3.0',
        );
        $new = TRUE;

        // Don't touch already imported objects unless running an update.
        if ($islandora_object && !$is_update) {
          drupal_set_message('Newspaper collection object exists');
          return array(
            'object' => $object_array['uuid'],
            'result' => FALSE,
            'message' => 'Newspaper collection object exists',
          );
        }
        elseif ($islandora_object) {
          $new = FALSE;
          // We're running an update.
        }

        // Set up islandora object.
        if (!$islandora_object) {
          $islandora_object = $tuque->repository->constructObject( "{$object_array['pid_namespace']}". ":{$object_array['uuid']}");
          $islandora_object->id = "{$object_array['pid_namespace']}" . ":{$object_array['uuid']}";
          $islandora_object->state = 'A';
          $islandora_object->label = $object_array['label'];
          $islandora_object->ownerId = $user->name;
          foreach ($content_models as $content_model) {
            $islandora_object->relationships->add(FEDORA_MODEL_URI, 'hasModel', $content_model);
          }
          $islandora_object->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $parent_collection);
        }
        else {
          $islandora_object->state = 'A';
          $islandora_object->label = $object_array['label'];
          $islandora_object->ownerId = $user->name;
        }


        $mods_record = ou_bagit_importer_run_xslt_transform(array(
          'input' => file_get_contents($object_array['metadata']['marcxml']),
          'xsl' => drupal_get_path('module', 'islandora_marcxml') . '/xsl/MARC21slim2MODS3-5.xsl',
        ));

        $dc_record = ou_bagit_importer_run_xslt_transform(array(
          'input' => $mods_record,
          'xsl' => drupal_get_path('module', 'islandora_batch') . '/transforms/mods_to_dc.xsl',
        ));

        $datastreams = array(
          'DC' => array(
            'type' => 'text/xml',
            'content' => $dc_record,
          ),
          'MODS' => array(
            'type' => 'text/xml',
            'content' => $mods_record,
          ),
        );

        if (!empty($object_array['metadata']['thumbnail'])) {
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $datastreams['OBJ'] = array(
            'type' => finfo_file($finfo, drupal_realpath($object_array['metadata']['thumbnail'])),
            'content' => file_get_contents($object_array['metadata']['thumbnail']),
          );
          finfo_close($finfo);
        }

        foreach ($datastreams as $key => $value) {

          if (!$islandora_object[$key]) {
            $ds = $islandora_object->constructDatastream($key);
            $ds->label = $key;
            $ds->mimetype = $value['type'];
            $ds->control_group = 'M';
            $ds->setContentFromString($value['content']);
            $islandora_object->ingestDatastream($ds);
          }
          else {
            $ds = $islandora_object[$key];
            // Only update this datastream if it has changed.
            if (md5($ds->content) != md5($value['content'])) {
              $ds->label = $key;
              $ds->mimetype = $value['type'];
              $ds->control_group = 'M';
              $ds->setContentFromString($value['content']);
            }
          }
        }
        if ($new) {
          islandora_add_object($islandora_object);
        }
        
        /**
         * The following piece of code is for issues
         */
        $issues_data = $this->recipe_data['issues'];
        
        
        return array(
          'object' => $islandora_object->id,
          'result' => TRUE,
          'message' => 'Successfully ingested/updated',
        );
    }
    
}