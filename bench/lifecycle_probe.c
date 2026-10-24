#include "php.h"
#include "zend_globals.h"
#include "ext/standard/info.h"
#include "SAPI.h"
#include "main/rfc1867.h"
#include <stdlib.h>
#include <string.h>
#include <dlfcn.h>

/* Test-only probes. This module is never installed into a service. */
static const char *probe_mode;
PHP_MINIT_FUNCTION(guard_oom_probe)
{
    probe_mode = getenv("GUARD_NATIVE_PROBE");
    if (probe_mode && strcmp(probe_mode, "early-consumer") == 0) {
        EG(delayed_oom).state = ZEND_OOM_STATE_PENDING;
        zend_error_zstr_at(E_WARNING, ZSTR_KNOWN(ZEND_STR_UNKNOWN_CAPITALIZED), 0,
            ZSTR_KNOWN(ZEND_STR_UNKNOWN_CAPITALIZED));
    }
    return SUCCESS;
}
static zend_result guard_probe_post_deactivate(void)
{
    if (probe_mode && strcmp(probe_mode, "dirty-root") == 0) {
        ZVAL_LONG(&EG(last_fatal_error_backtrace), 7);
    }
    return SUCCESS;
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_guard_probe, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, mode, IS_STRING, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_oom_probe)
{
    char *mode;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(mode, length)
    ZEND_PARSE_PARAMETERS_END();
    if (strcmp(mode, "error-consumer") == 0) {
        EG(delayed_oom).state = ZEND_OOM_STATE_PENDING;
        zend_error(E_WARNING, "GUARD error consumer probe");
    } else if (strcmp(mode, "delayed-consumer") == 0) {
        EG(delayed_oom).state = ZEND_OOM_STATE_PENDING;
        zend_handle_delayed_oom();
    } else if (strcmp(mode, "shutdown-consumer") == 0) {
        EG(delayed_oom).state = ZEND_OOM_STATE_PENDING;
    } else if (strcmp(mode, "session-consumer") == 0) {
        if (php_rfc1867_callback == NULL) {
            zend_throw_error(NULL, "Session upload callback is absent");
            RETURN_THROWS();
        }
        EG(delayed_oom).state = ZEND_OOM_STATE_PENDING;
        /* Barrier must exit before the callback can inspect this deliberately NULL payload. */
        void *extra = NULL;
        php_rfc1867_callback(MULTIPART_EVENT_START, NULL, &extra);
    } else if (strcmp(mode, "raw-oom") == 0) {
        void *impossible = pemalloc(SIZE_MAX, 1);
        pefree(impossible, 1);
    } else if (strcmp(mode, "policy") == 0) {
        RETURN_BOOL(dlsym(RTLD_DEFAULT, "zend_oom_guard_retire") != NULL);
    } else {
        zend_argument_value_error(1, "has an unknown test mode");
        RETURN_THROWS();
    }
    RETURN_TRUE;
}
static const zend_function_entry guard_probe_functions[] = {
    PHP_FE(guard_oom_probe, arginfo_guard_probe)
    PHP_FE_END
};
zend_module_entry guard_oom_probe_module_entry = {
    STANDARD_MODULE_HEADER,
    "guard_oom_probe",
    guard_probe_functions,
    PHP_MINIT(guard_oom_probe), NULL, NULL, NULL, NULL,
    "0.1.0",
    NO_MODULE_GLOBALS,
    guard_probe_post_deactivate,
    STANDARD_MODULE_PROPERTIES_EX
};
ZEND_GET_MODULE(guard_oom_probe)
