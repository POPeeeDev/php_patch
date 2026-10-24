#include "php.h"
#include "main/php_globals.h"
#include <dlfcn.h>
#include <stdint.h>
#include <string.h>

/* Isolated test extension, never an application or production dependency. */
ZEND_BEGIN_ARG_INFO_EX(arginfo_release_probe, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, operation, IS_STRING, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_release_probe)
{
    char *operation;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(operation, length)
    ZEND_PARSE_PARAMETERS_END();
    void (*check)(void) = (void (*)(void)) dlsym(RTLD_DEFAULT, "zend_oom_guard_check_request_state");
    if (strcmp(operation, "policy") == 0) {
        RETURN_BOOL(check != NULL);
    } else if (strcmp(operation, "raw") == 0) {
        void *p = pemalloc(SIZE_MAX, 1);
        pefree(p, 1);
    } else if (strcmp(operation, "clear") == 0) {
        RETURN_BOOL(PG(last_error_message) == NULL && PG(last_error_file) == NULL);
    } else if (strcmp(operation, "alias") == 0 || strcmp(operation, "dirty") == 0) {
        if (PG(last_error_message) || PG(last_error_file)) {
            zend_throw_error(NULL, "Clear error roots before the fixture");
            RETURN_THROWS();
        }
        zend_string *root = zend_string_init("guard-owned-alias", sizeof("guard-owned-alias") - 1, false);
        zend_string_addref(root);
        PG(last_error_message) = root;
        PG(last_error_file) = root;
        if (strcmp(operation, "dirty") == 0) {
            if (check == NULL) {
                zend_throw_error(NULL, "Guard checkpoint is absent");
                RETURN_THROWS();
            }
            check();
        }
    } else {
        zend_argument_value_error(1, "is unknown");
        RETURN_THROWS();
    }
    RETURN_TRUE;
}
static const zend_function_entry release_probe_functions[] = {
    PHP_FE(guard_release_probe, arginfo_release_probe)
    PHP_FE_END
};
zend_module_entry release_probe_module_entry = {
    STANDARD_MODULE_HEADER, "guard_release_probe", release_probe_functions,
    NULL, NULL, NULL, NULL, NULL, "0.2.0", STANDARD_MODULE_PROPERTIES
};
ZEND_GET_MODULE(release_probe)
