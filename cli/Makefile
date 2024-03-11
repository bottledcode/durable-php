TARGET := dphp-linux-x86_64
BIN_PATH := ../bin
DOCKER_IMAGE := builder
DOCKER_TARGET := cli-base-alpine
BUILD_PATH := /go/src/app/dist

${BIN_PATH}/${TARGET}: cli.go lib/*.go go.mod .vendor_modified build.sh init/* build-php.sh
	mkdir -p ${BIN_PATH}
	cd .. && docker build --pull --target ${DOCKER_TARGET} -t ${DOCKER_IMAGE} .
	docker create --name builder builder || ( docker rm -f builder && false )
	docker cp ${DOCKER_IMAGE}:${BUILD_PATH}/${TARGET} ${BIN_PATH}/${TARGET} || ( docker rm -f builder && false )
	docker rm -f builder
	upx -9 ../bin/dphp-*

# This will capture the most recently modified time among all files under vendor/ recursively
VENDOR_TIMESTAMP := $(shell find vendor -type f -print0 | xargs -0 stat -c '%Y' | sort -nr | head -n1)

.vendor_modified:
	@echo ${VENDOR_TIMESTAMP} > .vendor_modified
