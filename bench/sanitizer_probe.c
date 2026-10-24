#include "php.h"
#include <stdint.h>
#include <stdlib.h>
#include <limits.h>

/* Deliberately faulty, test-only controls; never load this module into a service. */
ZEND_BEGIN_ARG_INFO_EX(arginfo_memory_fault, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, kind, IS_STRING, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_memory_fault)
{
    char *kind;
    size_t length;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(kind, length)
    ZEND_PARSE_PARAMETERS_END();
    volatile size_t index = 16;
    volatile char *p = malloc(16);
    if (!p) { RETURN_FALSE; }
    if (strcmp(kind, "bounds") == 0) {
        p[index] = 7;
    } else if (strcmp(kind, "lifetime") == 0) {
        free((void *)p);
        p[0] = 7;
    } else if (strcmp(kind, "double-free") == 0) {
        free((void *)p);
        free((void *)p);
        RETURN_FALSE;
    } else if (strcmp(kind, "invalid-free") == 0) {
        free((void *)(p + 1));
        RETURN_FALSE;
    } else if (strcmp(kind, "overflow") == 0) {
        volatile int value = INT_MAX;
        value += 1;
    } else {
        p[0] = 1;
    }
    free((void *)p);
    RETURN_TRUE;
}
static const zend_function_entry guard_memory_functions[] = {
    PHP_FE(guard_memory_fault, arginfo_memory_fault)
    PHP_FE_END
};
zend_module_entry guard_memory_probe_module_entry = {
    STANDARD_MODULE_HEADER, "guard_memory_probe", guard_memory_functions,
    NULL, NULL, NULL, NULL, NULL, "0.1.0", STANDARD_MODULE_PROPERTIES
};
ZEND_GET_MODULE(guard_memory_probe)
