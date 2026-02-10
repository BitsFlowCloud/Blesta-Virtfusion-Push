<?php
/**
 * VirtFusion Push Base Controller
 */
class VirtfusionPushController extends AppController
{
    /**
     * Setup
     */
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        // Client controllers use views/client/default/ for views
        if (strpos(strtolower(get_class($this)), 'client') !== false) {
            $this->view->view = "client" . DS . "default";
        } else {
            $this->view->view = "default";
        }

        $this->requireLogin();

        Language::loadLang(
            [Loader::fromCamelCase(get_class($this))],
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );
        Language::loadLang(
            'virtfusion_push_controller',
            null,
            dirname(__FILE__) . DS . 'language' . DS
        );
    }
}
