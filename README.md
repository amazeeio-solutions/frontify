# Frontify

Integration of Frontify DAM
with the Drupal Media Library, Gutenberg and GraphQL v4.

## Quick start

- Enable the Frontify module
- Create a new media type, e.g. "Frontify Image" or "Frontify Video"
- Configure your Frontify url in Web Services > Frontify (/admin/config/frontify/settings)
- Add a Media Frontify field to e.g. a node type

## Difference between v2 and v3:

- Substitutes the Media Library with the Frontify Finder
- Generates a thumbnail for the Media entity on Media creation
- When inserting with the Media Library, optionally deduplicate media entities per media type
- Validation constraint to prevent to add multiple times in the global media library if deduplicate is enabled
- Optionally disable the possibility to add media entities in the global media library, per media type
- Isolates mime type groups for Frontify assets, just as Drupal does (Frontify Image, Frontify Document, Frontify Video)
- Moves the `alt text` field in a generic json field with other metadata, so it's not specific to images
and the field type can be used for Video, Documents, ...
- Make the Frontify field widget entity type agnostic, so it can also be used with other entity types than Media
- Adds the Frontify name so it can be used as the media name without using javascript specifics
- Adds the Frontify ID in the custom Frontify asset field so it can be used by other processes to interact with the Frontify API
- Adds an optional PHP GraphQL API wrapper for the Frontify API
- Integrates with Gutenberg Media library
- Integrates with [GraphQL v4 directives](https://packagist.org/packages/amazeelabs/graphql_directives) for responsive images
- Focal point support
- Video provider

### Use case for mime type groups

Media types have different metadata fields:
- Images can have alt text, caption, author
- Documents can have only the media name
- Videos can have a description, poster to be overridden

This allows
- more flexibility as a Drupal site builder
- less conditions and stronger typing in the code

### Use case for the PHP GraphQL API wrapper:

Fetch metadata from Frontify to populate metadata in source and Media translations.
This is purely optional, so the module can work without setting any API token.

## Work with the API

- Create a new API token in Frontify
- Add the token to the Drupal configuration
- Check `FrontifyApi.php`

## Translations approach

Frontify does not handle translations, so the Frontify field can stay
untranslatable, and translations should be added independently as separate
Drupal fields.

## Frontify documentation

- Developer documentation: https://developer.frontify.com/
- GraphQL API for assets: https://frontify.github.io/graphql-reference/queries/asset
- GraphiQL: https://frontify.github.io/public-api-explorer/example/current-user
- Frontify Finder: https://developer.frontify.com/d/XFPCrGNrXQQM/finder#/general/getting-started

## Pre-populate fields

Example code to pre-populate the `alt` and `author` field based on
Frontify metadata. Only one of these hooks should be needed, depending
on the context / use case.

### Media Library context with host entity

```php
/**
 * Implements hook_ENTITY_TYPE_create().
 *
 * Pre-populate Drupal fields based on Frontify metadata
 * in the Media Library context.
 */
function my_custom_frontify_media_create(EntityInterface $entity) {
  if (
    $entity->bundle() === 'frontify_image' &&
    $entity->hasField('field_media_frontify_image') &&
    !$entity->get('field_media_frontify_image')->isEmpty()
  ) {
    $entity->set('field_alt', $entity->get('field_media_frontify_image')->name);
    $metadata = $entity->get('field_media_frontify_image')->metadata;
    if (!empty($metadata)) {
      $metadata = json_decode($metadata, TRUE);
      if (!empty($metadata['author'])) {
        $entity->set('field_author', $metadata['author']);
      }
    }
  }
}
```

### Media add form context

This is a more theoretical example, as the Media add form might not be used
most of the time, because the main use case is Media Library references
in host entities.

But there could still be use cases to have standalone media entities, without
host entities.

```php
/**
 * Implements hook_ENTITY_TYPE_insert().
 *
 * Pre-populate custom fields based on Frontify metadata
 * in the Media add form context. It will not show up in the add form,
 * but right after save. So form modes can be used to get better UX.
 */
function my_custom_frontify_media_insert(EntityInterface $entity) {
  if (
    $entity->bundle() === 'frontify_image' &&
    $entity->hasField('field_media_frontify_image') &&
    !$entity->get('field_media_frontify_image')->isEmpty()
  ) {
    $entity->set('field_alt', $entity->get('field_media_frontify_image')->name);
    $metadata = $entity->get('field_media_frontify_image')->metadata;
    if (!empty($metadata)) {
      $metadata = json_decode($metadata, TRUE);
      if (!empty($metadata['author'])) {
        $entity->set('field_author', $metadata['author']);
      }
    }
    $entity->save();
  }
}
```

# Roadmap for contribution

- Create Document media type provider
- Config install and schema
- Upgrade path for the new field type schema
- Adjust the default field widget and field formatter accordingly
- Isolate Gutenberg specifics in a submodule
- Isolate GraphQL specifics in a submodule
- Tests
