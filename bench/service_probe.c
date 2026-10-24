#include "php.h"

/* Test-only nonfallible native allocation: no HTTP exploit and no installation. */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_hard_oom, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_test_hard_oom)
{
    ZEND_PARSE_PARAMETERS_NONE();
    void *p = emalloc(64 * 1024 * 1024);
    memset(p, 0, 1);
    efree(p);
}
static const zend_function_entry guard_service_functions[] = {
    PHP_FE(guard_test_hard_oom, arginfo_hard_oom)
    PHP_FE_END
};
zend_module_entry guard_service_probe_module_entry = {
    STANDARD_MODULE_HEADER, "guard_service_probe", guard_service_functions,
    NULL, NULL, NULL, NULL, NULL, "0.1.0", STANDARD_MODULE_PROPERTIES
};
ZEND_GET_MODULE(guard_service_probe)
