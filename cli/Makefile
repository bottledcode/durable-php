TARGET := dphp-linux-*
BIN_PATH := ../bin
DOCKER_IMAGE := builder
DOCKER_TARGET := cli-base-alpine
BUILD_PATH := /go/src/app/cli/dist

${BIN_PATH}/${TARGET}: cli.go */* go.mod build.sh build-php.sh ../Dockerfile
	mkdir -p ${BIN_PATH}
	cd .. && docker buildx build --pull --load --target ${DOCKER_TARGET} -t ${DOCKER_IMAGE} .
	docker create --name ${DOCKER_IMAGE} ${DOCKER_IMAGE} || ( docker rm -f ${DOCKER_IMAGE} && false )
	docker cp ${DOCKER_IMAGE}:${BUILD_PATH}/dphp ${BIN_PATH}/ || ( docker rm -f ${DOCKER_IMAGE} && false )
	docker rm -f ${DOCKER_IMAGE}
	upx -9 --force-pie ../bin/dphp-*

../dist: ${BIN_PATH}/${TARGET}
	docker create --name builder builder
	docker cp ${DOCKER_IMAGE}:${BUILD_PATH} ../dist
