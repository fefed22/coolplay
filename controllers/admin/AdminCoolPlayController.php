<?php
/**
 * CoolPlay - Vidéos produits sans impact sur la vitesse, pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'coolplay/classes/CplVideo.php';
require_once _PS_MODULE_DIR_ . 'coolplay/classes/CoolPlayApi.php';

/**
 * Contrôleur AJAX de gestion des vidéos, appelé depuis l'onglet CoolPlay de
 * la fiche produit (onglet BO invisible : aucune entrée de menu).
 * Toutes les réponses : JSON {ok, error?, rows?}.
 */
class AdminCoolPlayController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Compatibilité traduction cross-version : PrestaShop 9 a retiré la méthode
     * legacy l() des contrôleurs admin. On délègue au natif sur 1.7/8, sinon on
     * passe par le traducteur Symfony (repli : chaîne source).
     */
    public function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        if (method_exists(get_parent_class($this), 'l')) {
            return parent::l($string, $class, $addslashes, $htmlentities);
        }
        if (method_exists($this, 'trans')) {
            return $this->trans($string, array(), 'Modules.Coolplay.Admin');
        }

        return $string;
    }

    /**
     * Contrôleur AJAX uniquement : un accès direct n'affiche rien.
     */
    public function initContent()
    {
        $this->content = '';
        $this->context->smarty->assign('content', '');
    }

    /* ----------------------------------------------------------------------
     * Actions AJAX
     * -------------------------------------------------------------------- */

    public function ajaxProcessAddVideo()
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            $this->jsonOut(array('ok' => false, 'error' => $this->l('Produit inconnu.')));
        }
        $idShop = (int) $this->context->shop->id;

        // Le back-office saisit un seul titre : il vaut pour toutes les langues.
        $title = trim((string) Tools::getValue('title'));
        $titles = array();
        foreach (Language::getLanguages(false) as $lang) {
            $titles[(int) $lang['id_lang']] = $title;
        }

        $mode = (string) Tools::getValue('mode');
        try {
            if ($mode === 'youtube') {
                CoolPlayApi::addYoutube($idProduct, $idShop, Tools::getValue('url'), $titles);
            } elseif ($mode === 'file') {
                $video = $this->uploadedFile('cpl_file');
                if ($video === null) {
                    $this->jsonOut(array('ok' => false, 'error' => $this->l('Fichier vidéo manquant ou refusé (MP4 ou WebM uniquement, vérifiez aussi la limite d\'envoi de votre serveur).')));
                }
                $poster = $this->uploadedFile('cpl_poster');
                CoolPlayApi::addFile($idProduct, $idShop, $video['tmp_name'], $video['name'], $titles, $poster ? $poster['tmp_name'] : null);
            } else {
                $this->jsonOut(array('ok' => false, 'error' => $this->l('Action invalide.')));
            }
        } catch (CoolPlayApiException $e) {
            $this->jsonOut(array('ok' => false, 'error' => $this->errorMessage($e->getMessage())));
        }

        $this->jsonOut(array('ok' => true, 'rows' => $this->renderRows($idProduct)));
    }

    public function ajaxProcessDeleteVideo()
    {
        $video = $this->loadVideo();
        $this->callApi(function () use ($video) {
            CoolPlayApi::delete((int) $video->id, (int) $video->id_product, (int) $video->id_shop);
        });
        $this->jsonOut(array('ok' => true, 'rows' => $this->renderRows((int) $video->id_product)));
    }

    public function ajaxProcessToggleVideo()
    {
        $video = $this->loadVideo();
        $this->callApi(function () use ($video) {
            CoolPlayApi::update((int) $video->id, (int) $video->id_product, (int) $video->id_shop, array('active' => !$video->active));
        });
        $this->jsonOut(array('ok' => true, 'rows' => $this->renderRows((int) $video->id_product)));
    }

    /**
     * Monte ou descend d'un cran : échange avec la voisine, puis ordre absolu.
     */
    public function ajaxProcessMoveVideo()
    {
        $video = $this->loadVideo();
        $ids = array_column(CoolPlayApi::listForProduct((int) $video->id_product, (int) $video->id_shop), 'id');
        $i = array_search((int) $video->id, $ids, true);
        $j = Tools::getValue('dir') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            $ids[$i] = $ids[$j];
            $ids[$j] = (int) $video->id;
            $this->callApi(function () use ($video, $ids) {
                CoolPlayApi::reorder((int) $video->id_product, (int) $video->id_shop, $ids);
            });
        }
        $this->jsonOut(array('ok' => true, 'rows' => $this->renderRows((int) $video->id_product)));
    }

    public function ajaxProcessSaveTitle()
    {
        $video = $this->loadVideo();
        $title = trim((string) Tools::getValue('title'));
        $titles = array();
        foreach (Language::getLanguages(false) as $lang) {
            $titles[(int) $lang['id_lang']] = $title;
        }
        $this->callApi(function () use ($video, $titles) {
            CoolPlayApi::update((int) $video->id, (int) $video->id_product, (int) $video->id_shop, array('titles' => $titles));
        });
        $this->jsonOut(array('ok' => true));
    }

    /* ----------------------------------------------------------------------
     * Internes
     * -------------------------------------------------------------------- */

    /**
     * Vidéo de la requête, si elle appartient au produit envoyé et à la
     * boutique courante ; sinon sortie JSON en erreur.
     *
     * @return CplVideo
     */
    private function loadVideo()
    {
        try {
            return CoolPlayApi::load((int) Tools::getValue('id_video'), (int) Tools::getValue('id_product'), (int) $this->context->shop->id);
        } catch (CoolPlayApiException $e) {
            $this->jsonOut(array('ok' => false, 'error' => $this->l('Vidéo introuvable.')));
        }
    }

    private function callApi($fn)
    {
        try {
            $fn();
        } catch (CoolPlayApiException $e) {
            $this->jsonOut(array('ok' => false, 'error' => $this->errorMessage($e->getMessage())));
        }
    }

    /**
     * Fichier réellement reçu par envoi HTTP, ou null.
     *
     * @return array|null ['tmp_name', 'name']
     */
    private function uploadedFile($field)
    {
        if (empty($_FILES[$field]['tmp_name']) || !empty($_FILES[$field]['error'])
            || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            return null;
        }

        return array('tmp_name' => $_FILES[$field]['tmp_name'], 'name' => (string) $_FILES[$field]['name']);
    }

    private function errorMessage($code)
    {
        $messages = array(
            'not_found'      => $this->l('Vidéo introuvable.'),
            'bad_youtube'    => $this->l('URL YouTube non reconnue. Formats acceptés : youtube.com/watch?v=..., youtu.be/..., shorts, embed ou ID brut.'),
            'bad_file_type'  => $this->l('Fichier refusé : vidéo MP4 ou WebM, image d\'aperçu JPG, PNG ou WebP.'),
            'file_too_large' => $this->l('Fichier trop lourd : 100 Mo maximum pour une vidéo, 5 Mo pour une image d\'aperçu.'),
            'bad_order'      => $this->l('Ordre invalide.'),
            'save_failed'    => $this->l('Enregistrement impossible.'),
        );

        return isset($messages[$code]) ? $messages[$code] : $code;
    }

    private function renderRows($idProduct)
    {
        $module = Module::getInstanceByName('coolplay');

        return $module ? $module->renderProductRows((int) $idProduct) : '';
    }

    private function jsonOut(array $data)
    {
        header('Content-Type: application/json');
        die(json_encode($data));
    }
}
