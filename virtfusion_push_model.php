<?php
/**
 * VirtFusion Push Parent Model
 */
class VirtfusionPushModel extends AppModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Load components
        Loader::loadComponents($this, ['Record', 'Input']);

        // Load models
        Loader::loadModels($this, ['Clients', 'Services', 'ModuleManager']);
    }
}
