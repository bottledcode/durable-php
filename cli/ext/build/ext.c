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
    fprintf(stderr, "[C DEBUG] ext_create_object called!\n");
    fflush(stderr);
    
    /* Always allocate without properties due to corrupted class entry */
    ext_object *intern = ecalloc(1, sizeof(ext_object));
    
    fprintf(stderr, "[C DEBUG] About to call zend_object_std_init\n");
    fflush(stderr);
    
    zend_object_std_init(&intern->std, ce);
    
    fprintf(stderr, "[C DEBUG] zend_object_std_init completed\n");
    fflush(stderr);
    
    /* Skip object_properties_init due to corrupted class entry */
    
    intern->std.handlers = &object_handlers_ext;
    intern->go_handle = 0; /* will be set in __construct */

    fprintf(stderr, "[C DEBUG] ext_create_object returning\n");
    fflush(stderr);

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
static __thread uintptr_t current_go_worker_handle = 0; /* Thread-local Go worker handle */

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, __construct) {
    fprintf(stderr, "[C DEBUG] Constructor called\n");
    fflush(stderr);
    
    ZEND_PARSE_PARAMETERS_NONE();

    fprintf(stderr, "[C DEBUG] Constructor parameters parsed\n");
    fflush(stderr);

    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));

    fprintf(stderr, "[C DEBUG] Got intern object, go_handle=%lu\n", intern->go_handle);
    fflush(stderr);

    /* Constructor is called more than once, make it no-op */
    if (intern->go_handle != 0) {
        fprintf(stderr, "[C DEBUG] Constructor already called, returning\n");
        fflush(stderr);
        return;
    }

    /* Use the thread-local Go worker handle if available */
    if (current_go_worker_handle != 0) {
        fprintf(stderr, "[C DEBUG] Constructor using thread-local handle %lu\n", current_go_worker_handle);
        fflush(stderr);
        intern->go_handle = current_go_worker_handle;
    } else {
        /* Fallback: create a new Worker object */
        fprintf(stderr, "[C DEBUG] Constructor creating new Worker object\n");
        fflush(stderr);
        intern->go_handle = create_Worker_object();
    }
    
    fprintf(stderr, "[C DEBUG] Constructor completed\n");
    fflush(stderr);
}

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, __destruct) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));
    
    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_NONE();
    
    __destruct_wrapper(intern->go_handle);
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

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, setUser) {
    ext_object *intern = ext_object_from_obj(Z_OBJ_P(ZEND_THIS));

    zval *ht;

    VALIDATE_GO_HANDLE(intern);
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_OR_NULL(ht)
        ZEND_PARSE_PARAMETERS_END();

    setUser_wrapper(intern->go_handle, ht);
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

PHP_METHOD(Bottledcode_DurablePhp_Ext_Worker, GetCurrent) {
    fprintf(stderr, "[C DEBUG] GetCurrent method entry\n");
    fflush(stderr);
    
    ZEND_PARSE_PARAMETERS_NONE();
    
    fprintf(stderr, "[C DEBUG] GetCurrent called, thread-local handle=%lu\n", current_go_worker_handle);
    fflush(stderr);
    
    /* If no thread-local worker handle, return NULL */
    if (current_go_worker_handle == 0) {
        fprintf(stderr, "[C DEBUG] No thread-local handle, returning NULL\n");
        fflush(stderr);
        RETURN_NULL();
    }
    
    fprintf(stderr, "[C DEBUG] About to create Worker object with object_init_ex\n");
    fflush(stderr);
    
    /* Create a new Worker object - PHP constructor will pick up the thread-local handle */
    zval obj;
    if (object_init_ex(&obj, Worker_ce) != SUCCESS) {
        fprintf(stderr, "[C DEBUG] object_init_ex failed\n");
        fflush(stderr);
        RETURN_NULL();
    }
    
    fprintf(stderr, "[C DEBUG] object_init_ex succeeded, returning object\n");
    fflush(stderr);
    
    RETURN_ZVAL(&obj, 0, 0);
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

/* Function to set the current worker from Go */
void set_current_worker_handle(uintptr_t handle) {
    fprintf(stderr, "[C DEBUG] Setting thread-local worker handle to %lu\n", handle);
    fflush(stderr);
    current_go_worker_handle = handle;
}

/* Function to get current worker handle */
uintptr_t get_current_worker_handle() {
    return current_go_worker_handle;
}

/* Function to clear current worker */
void clear_current_worker() {
    fprintf(stderr, "[C DEBUG] Clearing thread-local worker handle\n");
    fflush(stderr);
    current_go_worker_handle = 0;
}

PHP_MINIT_FUNCTION(ext) {
    fprintf(stderr, "[C DEBUG] MINIT starting\n");
    fflush(stderr);
    
    register_all_classes();
    fprintf(stderr, "[C DEBUG] Classes registered\n");
    fflush(stderr);
    
    go_init_module();
    fprintf(stderr, "[C DEBUG] Go module initialized\n");
    fflush(stderr);
    
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

