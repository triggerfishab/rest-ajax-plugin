# REST AJAX

Utilizes WordPress REST API (instead of admin-ajax.php). This plugin works as a controller for the AJAX handlers. 

AJAX handlers lives in _app/AjaxHandler_. All files in _app/AjaxHandler_ uses the `App\AjaxHandler` namespace. 
AJAX handlers can be defined in two ways, and are called "method" or "class" based (AJAX) handlers.

1. A public static method in the default AJAX handler class `DefaultHandler.php`. _This is how a method based handler is defined._
2. It's own file and handler class. _This is how a class based handler is defined._

The class of a class based handler _must_ extend the abstract `Triggerfish\REST_Ajax\AbstractAjaxHandler` class and implement a public method called `__getData`.

## More in-depth information

Actions and handlers does not need to be registered like `tf_add_ajax_handler` or `add_action('wp_ajax_XXX')`.
The request is automatically mapped to a class or a method, specific to the current action.
All is mapped by the action sent in the request.
Automatic templating can be achieved.

### Request flow:

1. A class named like the action in StudlyCase will be searched in the following namespace, `App\AjaxHandler`. If such a class is found and has a public method named `__getData`, the flow will jump to 3. This is what is called "class based" handler below.

2. If a class cannot be found by 1, the fallback will be searched for. The fallback is a public static method named like the action in camelCase in `App\AjaxHandler\DefaultHandler`. This is what is called "method based" handler below.

3. Automatic templating.
   If the data from 1 or 2 is an array, a template named like the action in kebab-case will be searched for in a directory called "ajax" in the views directory.

   But if the handler is "class based", the class can define a public method named `__template` that return the preferred template's path. This will take precedence over, and fall back to, the template in the "ajax" directory from above.

   The data from 1 or 2 will be injected as the template will be included with the `App\template function`.

### Actions:
- tf/ajax/before
- tf/ajax/before/action=XX
- tf/ajax/after/action=XX
- tf/ajax/after/action=XX

### Filters:
- tf/ajax/result
- tf/ajax/result/action=XX
- tf/ajax/template_paths
- tf/ajax/template_paths/action=XX

## JSON request body and handler params

For `POST` requests with `Content-Type: application/json`, WordPress exposes the decoded body via `WP_REST_Request::get_json_params()`. Class-based handlers merge those values into the usual query/form params (later JSON keys override) and store the result on `AbstractAjaxHandler::$params`, so nested structures are real PHP arrays instead of `stdClass` objects (which avoids `foreach` type issues on PHP 8+).

The keys `searchArgs` and `taxonomyFilters` are normalized: existing arrays are kept as-is; objects are converted to arrays via JSON encode/decode; any other type becomes an empty array `[]`.

## Polylang

When Polylang is active, the controller sets the current language for the AJAX request in a context-aware way: **REST** contexts (`PLL_REST_Request`) use the language model only (no URL chooser). **Frontend** contexts use `PLL_Choose_Lang_Url` and the preferred language. Other Polylang setups fall back to resolving the language from the model.

## REST error responses

Failures are returned as REST errors with these `code` values (useful when debugging in the browser network panel or logs):

| Code | When |
|------|------|
| `rest_ajax_exception` | Uncaught exception or error while handling the request |
| `rest_ajax_validate` | Exception during `action` validation |
| `rest_ajax_template` | Exception while rendering the template |
| `no_handler` | No callable handler was resolved (should be rare) |

When `WP_DEBUG` is true, the error `message` may include the underlying exception message.

## Version and releases

The plugin header in `rest-ajax-plugin.php` carries the current version. Release builds use a **git tag** (for example `1.2.0`); keep the header in sync when tagging.

### 1.2.0

- Merge JSON body parameters into class-based handler params; normalize `searchArgs` / `taxonomyFilters` for PHP 8.
- Polylang: correct handling for REST (`PLL_REST_Request`) vs frontend.
- Harden REST flow with try/catch, structured `WP_Error` responses, and safer handler class validation (invalid base class fails validation instead of `E_USER_ERROR`).
