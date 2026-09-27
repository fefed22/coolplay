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

require_once dirname(__FILE__) . '/CplVideo.php';

class CoolPlayApiException extends Exception
{
    // getMessage() : code stable (not_found, bad_youtube, bad_file_type,
    // file_too_large, bad_order, save_failed).
}

/**
 * Gestion des vidéos d'un produit, indépendante du back-office : produit et
 * boutique sont passés en paramètre. Appelée par le contrôleur AJAX de la
 * fiche produit et par Régie (regiebridge). Chaque écriture renvoie la liste
 * à jour : ['videos' => listForProduct(), 'warnings' => string[]] (+ 'id' à l'ajout).
 */
class CoolPlayApi
{
    /** Incrémentée à chaque changement du contrat public. */
    const API_VERSION = 1;

    /** Taille maximale d'un fichier vidéo (100 Mo). */
    const MAX_VIDEO_BYTES = 104857600;

    /** Taille maximale d'une image d'aperçu (5 Mo). */
    const MAX_POSTER_BYTES = 5242880;

    /**
     * Les vidéos d'un produit dans une boutique, dans l'ordre d'affichage.
     *
     * @return array[] id, type, ref, titles [id_lang => string], active, position, thumb_url, video_url
     */
    public static function listForProduct($idProduct, $idShop)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cpl_video`
             WHERE id_product = ' . (int) $idProduct . ' AND id_shop = ' . (int) $idShop . '
             ORDER BY position ASC, id_cpl_video ASC'
        );
        $rows = is_array($rows) ? $rows : array();

        $titles = array();
        if ($rows) {
            $langRows = Db::getInstance()->executeS(
                'SELECT id_cpl_video, id_lang, title FROM `' . _DB_PREFIX_ . 'cpl_video_lang`
                 WHERE id_cpl_video IN (' . implode(',', array_map('intval', array_column($rows, 'id_cpl_video'))) . ')'
            );
            foreach (is_array($langRows) ? $langRows : array() as $l) {
                $titles[(int) $l['id_cpl_video']][(int) $l['id_lang']] = (string) $l['title'];
            }
        }

        $base = self::shopBaseUrl($idShop);
        $out = array();
        foreach ($rows as $row) {
            $thumb = CplVideo::thumbUrl($row);
            $out[] = array(
                'id'        => (int) $row['id_cpl_video'],
                'type'      => (string) $row['type'],
                'ref'       => (string) $row['video_ref'],
                'titles'    => isset($titles[(int) $row['id_cpl_video']]) ? $titles[(int) $row['id_cpl_video']] : array(),
                'active'    => (bool) $row['active'],
                'position'  => (int) $row['position'],
                'thumb_url' => ($thumb === '' || strpos($thumb, 'http') === 0) ? $thumb : $base . self::moduleRelative($thumb),
                'video_url' => $row['type'] === CplVideo::TYPE_YOUTUBE
                    ? 'https://www.youtube.com/watch?v=' . $row['video_ref']
                    : $base . self::moduleRelative(CplVideo::uploadUrl($row['video_ref'])),
            );
        }

        return $out;
    }

    /**
     * Ajoute une vidéo YouTube (URL ou identifiant). Titres par langue, facultatifs.
     *
     * @throws CoolPlayApiException
     */
    public static function addYoutube($idProduct, $idShop, $urlOrId, array $titles = array())
    {
        $ytId = CplVideo::parseYoutubeId($urlOrId);
        if ($ytId === '') {
            throw new CoolPlayApiException('bad_youtube');
        }

        // Miniature rapatriée en local : zéro requête tierce sur la fiche
        // produit avant le clic. Repli sur i.ytimg.com si YouTube est injoignable.
        $thumb = CplVideo::fetchYoutubeThumb($ytId);
        $warnings = $thumb === '' ? array('thumb_unavailable') : array();

        $id = self::insert($idProduct, $idShop, CplVideo::TYPE_YOUTUBE, $ytId, $thumb, $titles);

        return array('id' => $id, 'videos' => self::listForProduct($idProduct, $idShop), 'warnings' => $warnings);
    }

    /**
     * Ajoute un fichier vidéo déjà présent sur le disque du serveur (copié,
     * jamais déplacé : l'appelant reste maître de son fichier temporaire).
     * Contrôle l'extension, la signature réelle du fichier (MP4 / WebM) et la
     * taille. Aperçu facultatif (JPG / PNG / WebP), mêmes contrôles.
     *
     * @throws CoolPlayApiException
     */
    public static function addFile($idProduct, $idShop, $tmpPath, $originalName, array $titles = array(), $posterTmpPath = null)
    {
        $ext = self::checkVideo($tmpPath, $originalName);
        $posterExt = ($posterTmpPath !== null && $posterTmpPath !== '') ? self::checkPoster($posterTmpPath) : '';

        CplVideo::ensureDirs();
        $file = 'vid_' . (int) $idProduct . '_' . Tools::passwdGen(8) . '.' . $ext;
        if (!@copy($tmpPath, CplVideo::uploadsDir() . $file)) {
            throw new CoolPlayApiException('save_failed');
        }
        $poster = '';
        if ($posterExt !== '') {
            $poster = 'poster_' . (int) $idProduct . '_' . Tools::passwdGen(8) . '.' . $posterExt;
            if (!@copy($posterTmpPath, CplVideo::thumbsDir() . $poster)) {
                @unlink(CplVideo::uploadsDir() . $file);
                throw new CoolPlayApiException('save_failed');
            }
        }

        try {
            $id = self::insert($idProduct, $idShop, CplVideo::TYPE_FILE, $file, $poster, $titles);
        } catch (CoolPlayApiException $e) {
            @unlink(CplVideo::uploadsDir() . $file);
            if ($poster !== '') {
                @unlink(CplVideo::thumbsDir() . $poster);
            }
            throw $e;
        }

        return array('id' => $id, 'videos' => self::listForProduct($idProduct, $idShop), 'warnings' => array());
    }

    /**
     * Modifie une vidéo. $changes : 'titles' => [id_lang => string] (seules les
     * langues données changent), 'active' => bool. Les autres clés sont ignorées.
     *
     * @throws CoolPlayApiException
     */
    public static function update($idVideo, $idProduct, $idShop, array $changes)
    {
        $video = self::load($idVideo, $idProduct, $idShop);

        if (isset($changes['titles']) && is_array($changes['titles'])) {
            $langs = self::languageIds();
            foreach ($changes['titles'] as $idLang => $title) {
                if (in_array((int) $idLang, $langs, true)) {
                    $video->title[(int) $idLang] = self::cleanTitle($title);
                }
            }
        }
        if (array_key_exists('active', $changes)) {
            $video->active = $changes['active'] ? 1 : 0;
        }

        try {
            $saved = $video->update();
        } catch (Exception $e) {
            $saved = false;
        }
        if (!$saved) {
            throw new CoolPlayApiException('save_failed');
        }

        return array('videos' => self::listForProduct($idProduct, $idShop), 'warnings' => array());
    }

    /**
     * Ordre absolu : $idsInOrder doit contenir exactement les vidéos du produit
     * dans cette boutique. Les positions sont renumérotées 1, 2, 3… sans trou.
     *
     * @throws CoolPlayApiException
     */
    public static function reorder($idProduct, $idShop, array $idsInOrder)
    {
        $ids = array_map('intval', array_values($idsInOrder));
        $current = array_column(self::listForProduct($idProduct, $idShop), 'id');
        $sortedIds = $ids;
        sort($sortedIds);
        sort($current);
        if ($sortedIds !== $current) {
            throw new CoolPlayApiException('bad_order');
        }

        $db = Db::getInstance();
        foreach ($ids as $i => $id) {
            try {
                $saved = $db->update('cpl_video', array('position' => $i + 1), 'id_cpl_video = ' . (int) $id);
            } catch (Exception $e) {
                $saved = false;
            }
            if (!$saved) {
                throw new CoolPlayApiException('save_failed');
            }
        }

        return array('videos' => self::listForProduct($idProduct, $idShop), 'warnings' => array());
    }

    /**
     * Supprime une vidéo et ses fichiers, après contrôle d'appartenance.
     *
     * @throws CoolPlayApiException
     */
    public static function delete($idVideo, $idProduct, $idShop)
    {
        $video = self::load($idVideo, $idProduct, $idShop);
        try {
            $deleted = $video->delete();
        } catch (Exception $e) {
            $deleted = false;
        }
        if (!$deleted) {
            throw new CoolPlayApiException('save_failed');
        }

        return array('videos' => self::listForProduct($idProduct, $idShop), 'warnings' => array());
    }

    /**
     * Vidéo chargée si elle appartient bien au produit et à la boutique donnés.
     *
     * @return CplVideo
     *
     * @throws CoolPlayApiException
     */
    public static function load($idVideo, $idProduct, $idShop)
    {
        $video = new CplVideo((int) $idVideo);
        if (!Validate::isLoadedObject($video)
            || (int) $video->id_product !== (int) $idProduct
            || (int) $video->id_shop !== (int) $idShop) {
            throw new CoolPlayApiException('not_found');
        }

        return $video;
    }

    /* ----------------------------------------------------------------------
     * Internes
     * -------------------------------------------------------------------- */

    /**
     * @return int id de la vidéo créée
     *
     * @throws CoolPlayApiException
     */
    protected static function insert($idProduct, $idShop, $type, $ref, $thumb, array $titles)
    {
        $video = new CplVideo();
        $video->id_product = (int) $idProduct;
        $video->id_shop = (int) $idShop;
        $video->type = $type;
        $video->video_ref = $ref;
        $video->thumb = $thumb;
        $video->position = CplVideo::maxPosition($idProduct, $idShop) + 1;
        $video->active = 1;
        foreach (self::languageIds() as $idLang) {
            $video->title[$idLang] = isset($titles[$idLang]) ? self::cleanTitle($titles[$idLang]) : '';
        }

        try {
            $saved = $video->add();
        } catch (Exception $e) {
            $saved = false;
        }
        if (!$saved) {
            throw new CoolPlayApiException('save_failed');
        }

        return (int) $video->id;
    }

    /**
     * @return string extension retenue (mp4 | webm)
     *
     * @throws CoolPlayApiException
     */
    protected static function checkVideo($path, $originalName)
    {
        $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, array('mp4', 'webm'), true) || !is_file($path) || !is_readable($path)) {
            throw new CoolPlayApiException('bad_file_type');
        }
        if (filesize($path) > self::MAX_VIDEO_BYTES) {
            throw new CoolPlayApiException('file_too_large');
        }

        // Signature réelle du fichier : boîte « ftyp » d'un conteneur MP4
        // (octets 4 à 7), en-tête EBML d'un WebM. Une extension ne prouve rien.
        $head = (string) @file_get_contents($path, false, null, 0, 12);
        $isMp4 = strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp';
        $isWebm = strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0;
        if (($ext === 'mp4' && !$isMp4) || ($ext === 'webm' && !$isWebm)) {
            throw new CoolPlayApiException('bad_file_type');
        }

        return $ext;
    }

    /**
     * @return string extension retenue (jpg | png | webp)
     *
     * @throws CoolPlayApiException
     */
    protected static function checkPoster($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CoolPlayApiException('bad_file_type');
        }
        if (filesize($path) > self::MAX_POSTER_BYTES) {
            throw new CoolPlayApiException('file_too_large');
        }
        $info = @getimagesize($path);
        $types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png');
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        if (!is_array($info) || !isset($types[$info[2]])) {
            throw new CoolPlayApiException('bad_file_type');
        }

        return $types[$info[2]];
    }

    protected static function cleanTitle($title)
    {
        return Tools::substr(trim((string) $title), 0, 255);
    }

    /**
     * @return int[]
     */
    protected static function languageIds()
    {
        return array_map('intval', array_column(Language::getLanguages(false), 'id_lang'));
    }

    /**
     * URL racine de la boutique (domaine + dossier), https si activé.
     */
    protected static function shopBaseUrl($idShop)
    {
        $shop = new Shop((int) $idShop);

        return Validate::isLoadedObject($shop) ? $shop->getBaseURL(true) : Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
    }

    /**
     * Chemin relatif à la racine boutique d'une URL construite par CplVideo
     * (qui la préfixe du dossier de la boutique courante).
     */
    protected static function moduleRelative($url)
    {
        $pos = strpos($url, 'modules/coolplay/');

        return $pos === false ? ltrim($url, '/') : substr($url, $pos);
    }
}
