#ifndef _EXT_H
#define _EXT_H

#include <php.h>
#include <stdint.h>

extern zend_module_entry ext_module_entry;

/* Functions for managing current worker */
void set_current_worker_handle(uintptr_t handle);
void clear_current_worker(void);

#endif
