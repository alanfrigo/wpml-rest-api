<?php

/*
Plugin Name: WPML REST API
Version: 2.0.1
Description: Adds links to posts in other languages into the results of a WP REST API query for sites running the WPML plugin.
Author: Shawn Hooper
Author URI: https://profiles.wordpress.org/shooper
*/

namespace ShawnHooper\WPML;

use WP_REST_Request;
use WP_Error;
use RuntimeException;

class WPML_REST_API
{

    private array $translations = [];

    public function wordpress_hooks(): void
    {
        \add_action('rest_api_init', [$this, 'init'], 1000);
    }

    public function init(): void
    {
        // Verifica se o WPML está instalado
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        if (!\is_plugin_active('sitepress-multilingual-cms/sitepress.php')) {
            return;
        }

        // Inclui as funções da API do WPML
        include_once WP_PLUGIN_DIR . '/sitepress-multilingual-cms/inc/wpml-api.php';

        $available_languages = \wpml_get_active_languages_filter('', ['skip_missing' => false]);

        if ((!empty($available_languages) && !isset($GLOBALS['icl_language_switched'])) || !$GLOBALS['icl_language_switched']) {
            if (isset($_REQUEST['wpml_lang'])) {
                $lang = $_REQUEST['wpml_lang'];
            } else if (isset($_REQUEST['lang'])) {
                $lang = $_REQUEST['lang'];
            }

            if (isset($lang) && array_key_exists($lang, $available_languages)) {
                \do_action('wpml_switch_language', $lang);
            }
        }

        // Adiciona campos do WPML a todos os tipos de post
        $post_types = \get_post_types(array('public' => true, 'exclude_from_search' => false));
        foreach ($post_types as $post_type) {
            $this->register_api_field($post_type);
        }
    }

    /**
     * @param string $post_type
     * @return void
     */
    public function register_api_field(string $post_type): void
    {
        \register_rest_field($post_type,
            'wpml_current_locale',
            array(
                'get_callback' => [$this, 'get_current_locale'],
                'update_callback' => [$this, 'update_current_locale'],
                'schema' => null,
            )
        );

        \register_rest_field($post_type,
            'wpml_translations',
            array(
                'get_callback' => [$this, 'get_translations'],
                'update_callback' => [$this, 'update_translations'],
                'schema' => null,
            )
        );
    }

    /**
     * Manipulador do ENDPOINT da REST API
     *
     * Recupera o locale atual
     *
     * @param array|object $object Detalhes do post atual.
     * @param string $field_name Nome do campo.
     * @param WP_REST_Request $request Requisição atual.
     *
     * @return string
     * @throws RuntimeException
     */
    public function get_current_locale($object, string $field_name, WP_REST_Request $request): string
    {
        $langInfo = \wpml_get_language_information($object);
        if (\is_wp_error($langInfo)) {
            throw new RuntimeException('Não foi possível recuperar o locale atual');
        }
        return $langInfo['locale'];
    }

    /**
     * Manipulador do ENDPOINT da REST API
     *
     * Atualiza o locale atual
     *
     * @param mixed $value O novo valor para o campo.
     * @param object $object O objeto da resposta.
     * @param string $field_name O nome do campo.
     *
     * @return true|WP_Error
     */
    public function update_current_locale($value, $object, string $field_name)
    {
        $post_id = $object->ID;

        // Verifica se o usuário tem permissão para editar o post
        if (!\current_user_can('edit_post', $post_id)) {
            return new WP_Error('rest_cannot_edit', __('Desculpe, você não tem permissão para editar este post.'), array('status' => \rest_authorization_required_code()));
        }

        // Valida o locale fornecido
        $languages = \apply_filters('wpml_active_languages', null);
        $locales = array();
        $locale_to_lang_code = array();
        foreach ($languages as $lang) {
            $locales[] = $lang['default_locale'];
            $locale_to_lang_code[$lang['default_locale']] = $lang['language_code'];
        }

        if (!in_array($value, $locales)) {
            return new WP_Error('rest_invalid_locale', __('Locale fornecido é inválido.'), array('status' => 400));
        }

        $language_code = $locale_to_lang_code[$value];

        $element_id = $post_id;
        $element_type = 'post_' . $object->post_type;

        // Obtém os detalhes de idioma do elemento atual
        $element_language_details = \apply_filters('wpml_element_language_details', null, array('element_id' => $element_id, 'element_type' => $element_type));

        if (empty($element_language_details)) {
            return new WP_Error('rest_language_details_not_found', __('Detalhes de idioma não encontrados para o post.'), array('status' => 500));
        }

        $trid = $element_language_details->trid;

        // Atualiza os detalhes do idioma usando o objeto $sitepress
        global $sitepress;
        if (method_exists($sitepress, 'set_element_language_details')) {
            $result = $sitepress->set_element_language_details($element_id, $element_type, $trid, $language_code);
        } else {
            return new WP_Error('rest_method_not_exists', __('O método set_element_language_details não existe no objeto $sitepress.'), array('status' => 500));
        }

        if (!$result) {
            return new WP_Error('rest_language_update_failed', __('Falha ao atualizar o idioma do post.'), array('status' => 500));
        }

        return true;
    }

    /**
     * Manipulador do ENDPOINT da REST API
     *
     * Recupera as traduções disponíveis
     *
     * @param array|object $object Detalhes do post atual.
     * @param string $field_name Nome do campo.
     * @param WP_REST_Request $request Requisição atual.
     *
     * @return array
     */
    public function get_translations($object, string $field_name, WP_REST_Request $request): array
    {
        $this->translations = []; // Inicializa o array de traduções
        $languages = \apply_filters('wpml_active_languages', null);

        foreach ($languages as $language) {
            $this->get_translations_for_language($object, $language);
        }

        return $this->translations;
    }

    /**
     * Manipulador do ENDPOINT da REST API
     *
     * Atualiza as traduções
     *
     * @param mixed $value O novo valor para o campo.
     * @param object $object O objeto da resposta.
     * @param string $field_name O nome do campo.
     *
     * @return true|WP_Error
     */
    public function update_translations($value, $object, string $field_name)
    {
        $post_id = $object->ID;

        // Verifica se o usuário tem permissão para editar o post
        if (!\current_user_can('edit_post', $post_id)) {
            return new WP_Error('rest_cannot_edit', __('Desculpe, você não tem permissão para editar este post.'), array('status' => \rest_authorization_required_code()));
        }

        if (!is_array($value)) {
            return new WP_Error('rest_invalid_translations', __('Traduções fornecidas são inválidas.'), array('status' => 400));
        }

        // Obtém os detalhes de idioma do elemento atual
        $element_id = $post_id;
        $element_type = 'post_' . $object->post_type;

        $element_language_details = \apply_filters('wpml_element_language_details', null, array('element_id' => $element_id, 'element_type' => $element_type));

        if (empty($element_language_details)) {
            return new WP_Error('rest_language_details_not_found', __('Detalhes de idioma não encontrados para o post.'), array('status' => 500));
        }

        $trid = $element_language_details->trid;

        // Obtém os idiomas disponíveis
        $languages = \apply_filters('wpml_active_languages', null);
        $locale_to_lang_code = array();
        foreach ($languages as $lang) {
            $locale_to_lang_code[$lang['default_locale']] = $lang['language_code'];
        }

        // Para cada tradução fornecida, atualiza os detalhes do idioma com o mesmo 'trid'
        global $sitepress;
        foreach ($value as $locale => $translation_id) {
            // Valida o ID da tradução
            $translation_post = \get_post($translation_id);
            if (!$translation_post) {
                return new WP_Error('rest_invalid_translation_id', __('ID de post de tradução inválido fornecido.'), array('status' => 400));
            }

            // Verifica se o usuário pode editar o post de tradução
            if (!\current_user_can('edit_post', $translation_id)) {
                return new WP_Error('rest_cannot_edit_translation', __('Desculpe, você não tem permissão para editar este post de tradução.'), array('status' => \rest_authorization_required_code()));
            }

            // Obtém o código do idioma a partir do locale
            if (!isset($locale_to_lang_code[$locale])) {
                return new WP_Error('rest_invalid_locale', __('Locale fornecido é inválido.'), array('status' => 400));
            }

            $language_code = $locale_to_lang_code[$locale];

            $translation_element_id = $translation_id;
            $translation_element_type = 'post_' . $translation_post->post_type;

            if (method_exists($sitepress, 'set_element_language_details')) {
                $result = $sitepress->set_element_language_details($translation_element_id, $translation_element_type, $trid, $language_code);
            } else {
                return new WP_Error('rest_method_not_exists', __('O método set_element_language_details não existe no objeto $sitepress.'), array('status' => 500));
            }

            if (!$result) {
                return new WP_Error('rest_translation_update_failed', __('Falha ao atualizar a tradução.'), array('status' => 500));
            }
        }

        return true;
    }

    /**
     * @param array|object $object
     * @param array $language
     * @return void
     */
    private function get_translations_for_language(array $object, array $language) : void {
        $post_id = wpml_object_id_filter($object['id'], 'post', false, $language['language_code']);
        $thisPost = get_post($post_id);

        $translation = [
            'locale' => $language['default_locale'],            
        ];
    
        // Check if the post has translations
        if ($post_id === null) {
            // Behavior when no translations are available
            $translation['translation'] = false;
        } elseif ($post_id === $object['id']) {
            $translation['translation'] = 'current';
        } else {
            // Behavior when translations are available
            $translation['id'] = $thisPost->ID;
            $translation['slug'] = $thisPost->post_name;
            $translation['post_title'] = $thisPost->post_title;
            $translation['href'] = get_permalink($thisPost);
        }
    
        $this->translations[$language['default_locale']] = apply_filters('wpmlrestapi_get_translation', $translation, $thisPost, $language);
    }
}

$GLOBALS['WPML_REST_API'] = new WPML_REST_API();
$GLOBALS['WPML_REST_API']->wordpress_hooks();
