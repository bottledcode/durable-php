package ext

import (
	"C"
	"github.com/dunglas/frankenphp"
	"strings"
	"unsafe"
)

func init() {
	frankenphp.RegisterExtension(unsafe.Pointer(&C.ext_module_entry))
}

// export_php:module init
func moduleInit() {
	// this is during init
}

// export_php:function repeat_this(string $id, string $str, int $count, bool $reverse): string
func repeat_this(idStr *C.zend_string, s *C.zend_string, count int, reverse bool) unsafe.Pointer {
	str := frankenphp.GoString(unsafe.Pointer(s))

	result := strings.Repeat(str, count)

	if reverse {
		runes := []rune(result)
		for i, j := 0, len(runes)-1; i < j; i, j = i+1, j-1 {
			runes[i], runes[j] = runes[j], runes[i]
		}
		result = string(runes)
	}

	return frankenphp.PHPString(result, false)
}
