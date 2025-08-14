/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: ad9b266d342856e3f582dcae9b4f25192cc52140 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Bottledcode_DurablePhp_Ext_emit_event, 0, 3, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, userContext, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, event, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, from, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_GetCurrent, 0, 0, Bottledcode\\DurablePhp\\Ext\\Worker, 1)
ZEND_END_ARG_INFO()

#define arginfo_class_Bottledcode_DurablePhp_Ext_Worker___destruct arginfo_class_Bottledcode_DurablePhp_Ext_Worker___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_queryState, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, stateId, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getUser, 0, 0, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getSource, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getCurrentId arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getSource

#define arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getCorrelationId arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getSource

#define arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getState arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getUser

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_updateState, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, state, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_emitEvent, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, eventDescription, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Bottledcode_DurablePhp_Ext_Worker_delete, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(Bottledcode_DurablePhp_Ext_emit_event);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, __construct);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, GetCurrent);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, __destruct);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, queryState);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, getUser);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, getSource);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, getCurrentId);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, getCorrelationId);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, getState);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, updateState);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, emitEvent);
ZEND_METHOD(Bottledcode_DurablePhp_Ext_Worker, delete);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Bottledcode\\DurablePhp\\Ext", "emit_event"), zif_Bottledcode_DurablePhp_Ext_emit_event, arginfo_Bottledcode_DurablePhp_Ext_emit_event, 0, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Bottledcode_DurablePhp_Ext_Worker_methods[] = {
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, __construct, arginfo_class_Bottledcode_DurablePhp_Ext_Worker___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, GetCurrent, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_GetCurrent, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, __destruct, arginfo_class_Bottledcode_DurablePhp_Ext_Worker___destruct, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, queryState, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_queryState, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, getUser, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getUser, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, getSource, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getSource, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, getCurrentId, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getCurrentId, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, getCorrelationId, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getCorrelationId, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, getState, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_getState, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, updateState, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_updateState, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, emitEvent, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_emitEvent, ZEND_ACC_PUBLIC)
	ZEND_ME(Bottledcode_DurablePhp_Ext_Worker, delete, arginfo_class_Bottledcode_DurablePhp_Ext_Worker_delete, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Bottledcode_DurablePhp_Ext_Worker(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Bottledcode\\DurablePhp\\Ext", "Worker", class_Bottledcode_DurablePhp_Ext_Worker_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, 0);

	return class_entry;
}
