TARGET := dphp-linux-x86_64
BIN_PATH := ../bin
DOCKER_IMAGE := builder
DOCKER_TARGET := cli-base-alpine
BUILD_PATH := /go/src/app/dist

${BIN_PATH}/${TARGET}: cli.go lib/* go.mod build.sh init/* build-php.sh config/* auth/*
	mkdir -p ${BIN_PATH}
	cd .. && docker build --pull --target ${DOCKER_TARGET} -t ${DOCKER_IMAGE} .
	docker create --name builder builder || ( docker rm -f builder && false )
	docker cp ${DOCKER_IMAGE}:${BUILD_PATH}/${TARGET} ${BIN_PATH}/${TARGET} || ( docker rm -f builder && false )
	docker rm -f builder
	upx -9 --force-pie ../bin/dphp-*
