
# WPML REST API
================

WordPress plugin to add links to posts in other languages to REST API responses

## Introduction

The WPML REST API is a WordPress plugin that adds links to posts in other languages to REST API responses. It is designed for sites that use the WPML internationalization plugin.

## Features

* Adds `wpml_current_locale` field to REST API responses, showing the current language of the post.
* Adds `wpml_translations` field to REST API responses, showing the available translations for the post.
* Allows updating the current language of the post.
* Allows updating the post's translations.

## Installation

To install the WPML REST API, follow these steps:

1. Download the plugin from your preferred repository.
2. Extract the file to your WordPress plugin directory.
3. Update the plugin in your WordPress dashboard.

## Configuration

The plugin does not require additional configuration, as it is designed to work with WPML by default.

## Usage

### Get the current language of the post

To get the current language of the post, use the REST API `posts/{post_id}/wpml-current-locale`.

Example:
```json
GET /wp-json/wp/v2/posts/123/wpml-current-locale
```
### Get the post's translations

To get the post's translations, use the REST API `posts/{post_id}/wpml-translations`.

Example:
```json
GET /wp-json/wp/v2/posts/123/wpml-translations
```
### Update the current language of the post

To update the current language of the post, use the REST API `posts/{post_id}/wpml-update-locale`.

Example:
```json
POST /wp-json/wp/v2/posts/123/wpml-update-locale
{
  "locale": "pt_BR"
}
```
### Update the post's translations

To update the post's translations, use the REST API `posts/{post_id}/wpml-update-translations`.

Example:
```json
POST /wp-json/wp/v2/posts/123/wpml-update-translations
{
  "translations": {
    "pt_BR": 456,
    "fr_FR": 789
  }
}
```
## Credits

This plugin is based on the original work of [Shawn Hooper](https://github.com/shawnhooper).

## Development

This plugin is developed to provide a REST API for sites that use WPML. If you'd like to contribute to the plugin's development, please send a pull request to our repository.

## License

This plugin is licensed under the GPL license, which is a free software license.
