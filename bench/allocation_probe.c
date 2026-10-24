#include "php.h"
#include "zend_alloc.h"
#include "main/php_globals.h"

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_arm_allocations, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, count, IS_LONG, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_roots, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_test_alias_roots)
{
    ZEND_PARSE_PARAMETERS_NONE();
    if (PG(last_error_message) || PG(last_error_file)) {
        zend_throw_error(NULL, "Clear existing error roots before injecting valid aliases");
        RETURN_THROWS();
    }
    zend_string *root = zend_string_init("guard-alias", sizeof("guard-alias") - 1, false);
    zend_string_addref(root);
    PG(last_error_message) = root;
    PG(last_error_file) = root;
    RETURN_TRUE;
}
PHP_FUNCTION(guard_test_roots_clear)
{
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(PG(last_error_message) == NULL && PG(last_error_file) == NULL);
}
PHP_FUNCTION(guard_test_arm_allocations)
{
    zend_long count;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(count)
    ZEND_PARSE_PARAMETERS_END();
    if (count < 1 || count > 8192) {
        zend_argument_value_error(1, "must be between 1 and 8192");
        RETURN_THROWS();
    }
    zend_mm_set_max_allocs((uint64_t)count);
}
static const zend_function_entry guard_allocation_functions[] = {
    PHP_FE(guard_test_arm_allocations, arginfo_arm_allocations)
    PHP_FE(guard_test_alias_roots, arginfo_roots)
    PHP_FE(guard_test_roots_clear, arginfo_roots)
    PHP_FE_END
};
zend_module_entry guard_allocation_probe_module_entry = {
    STANDARD_MODULE_HEADER, "guard_allocation_probe", guard_allocation_functions,
    NULL, NULL, NULL, NULL, NULL, "0.1.0", STANDARD_MODULE_PROPERTIES
};
ZEND_GET_MODULE(guard_allocation_probe)
