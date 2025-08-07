#include <php.h>
#include <Zend/zend_API.h>
#include <Zend/zend_hash.h>
#include <Zend/zend_types.h>
#include <stddef.h>

#include "ext.h"
#include "ext_arginfo.h"
#include "_cgo_export.h"

#define VALIDATE_GO_HANDLE(intern) \
    do { \
        if ((intern)->go_handle == 0) { \
            zend_throw_error(NULL, "Go object not found in registry"); \
            RETURN_THROWS(); \
        } \
    } while (0)

static zend_object_handlers object_handlers_ext;

typedef struct {
    uintptr_t go_handle;
    zend_object std; /* This must be the last field in the structure: the property store starts at this offset */
} ext_object;

static inline ext_object *ext_object_from_obj(zend_object *obj) {
    return (ext_object*)((char*)(obj) - offsetof(ext_object, std));
}

static zend_object *ext_create_object(zend_class_entry *ce) {
    ext_object *intern = ecalloc(1, sizeof(ext_object) + zend_object_properties_size(ce));
    
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    
    intern->std.handlers = &object_handlers_ext;
    intern->go_handle = 0; /* will be set in __construct */

    return &intern->std;
}

static void ext_free_object(zend_object *object) {
    ext_object *intern = ext_object_from_obj(object);

    if (intern->go_handle != 0) {
        removeGoObject(intern->go_handle);
    }
    
    zend_object_std_dtor(&intern->std);
}

void init_object_handlers() {
    memcpy(&object_handlers_ext, &std_object_handlers, sizeof(zend_object_handlers));
    object_handlers_ext.free_obj = ext_free_object;
    object_handlers_ext.clone_obj = NULL;
    object_handlers_ext.offset = offsetof(ext_object, std);
}

static zend_class_entry *Worker_ce = NULL;

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, __construct) {
    ZEND_PARSE_PARAMETERS_NONE();

    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));

    /* Constructor is called more than once, make it no-op */
    if (intern->go_handle != 0) {
        return;
    }

    intern->go_handle = create_Worker_object();
}


PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, startEventLoop) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    zend_string *kind = NULL;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(kind)
        ZEND_PARSE_PARAMETERS_END();
    
    startEventLoop_wrapper(intern->go_handle, kind);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, drainEventLoop) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    drainEventLoop_wrapper(intern->go_handle);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, __destruct) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    __destruct_wrapper(intern->go_handle);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getNextEvent) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    zend_string* result = getNextEvent_wrapper(intern->go_handle);
    RETURN_STR(result);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, queryState) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    zend_string *stateId = NULL;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(stateId)
        ZEND_PARSE_PARAMETERS_END();
    
    void* result = queryState_wrapper(intern->go_handle, stateId);
    if (result != NULL) {
        HashTable *ht = (HashTable*)result;
        RETURN_ARR(ht);
    } else {
        RETURN_NULL();
    }
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getUser) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    void* result = getUser_wrapper(intern->go_handle);
    if (result != NULL) {
        HashTable *ht = (HashTable*)result;
        RETURN_ARR(ht);
    } else {
        RETURN_NULL();
    }
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getSource) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    zend_string* result = getSource_wrapper(intern->go_handle);
    RETURN_STR(result);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getCurrentId) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    zend_string* result = getCurrentId_wrapper(intern->go_handle);
    RETURN_STR(result);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getCorrelationId) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    zend_string* result = getCorrelationId_wrapper(intern->go_handle);
    RETURN_STR(result);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, getState) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    void* result = getState_wrapper(intern->go_handle);
    if (result != NULL) {
        HashTable *ht = (HashTable*)result;
        RETURN_ARR(ht);
    } else {
        RETURN_NULL();
    }
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, updateState) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    zval *state = NULL;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(state)
        ZEND_PARSE_PARAMETERS_END();
    
    updateState_wrapper(intern->go_handle, state);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, emitEvent) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    zval *eventDescription = NULL;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(eventDescription)
        ZEND_PARSE_PARAMETERS_END();
    
    emitEvent_wrapper(intern->go_handle, eventDescription);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, delete) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    delete_wrapper(intern->go_handle);
}

void register_all_classes() {
    init_object_handlers();
    Worker_ce = register_class_Bottledcode_DurablePhp_Ext_Worker();
    if (!Worker_ce) {
        php_error_docref(NULL, E_ERROR, "Failed to register class Worker");
        return;
    }
    Worker_ce->create_object = ext_create_object;
}

PHP_MINIT_FUNCTION(ext) {
    register_all_classes();
    
    
    go_init_module();
    
    
    return SUCCESS;
}



PHP_MSHUTDOWN_FUNCTION(ext) {
    go_shutdown_module();
    return SUCCESS;
}



zend_module_entry ext_module_entry = {STANDARD_MODULE_HEADER,
                                         "ext",
                                         ext_functions,             /* Functions */
                                         PHP_MINIT(ext),  /* MINIT */
                                         PHP_MSHUTDOWN(ext),  /* MSHUTDOWN */
                                         NULL,                      /* RINIT */
                                         NULL,                      /* RSHUTDOWN */
                                         NULL,                      /* MINFO */
                                         "1.0.0",                   /* Version */
                                         STANDARD_MODULE_PROPERTIES};

PHP_FUNCTION(Bottledcode_DurablePhp_Ext_emit_event)
{
    zval *userContext = NULL;
    zval *event = NULL;
    zend_string *from = NULL;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ARRAY_OR_NULL(userContext)
        Z_PARAM_ARRAY(event)
        Z_PARAM_STR(from)
    ZEND_PARSE_PARAMETERS_END();
    long result = emit_event(userContext, event, from);
    RETURN_LONG(result);
}

