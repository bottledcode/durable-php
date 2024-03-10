package init

import (
	"embed"
	"go.uber.org/zap"
	"html/template"
	"io"
	"os"
	"path"
	"strings"
)

//go:embed template/*
var skel embed.FS

func createProjectFolder(name string) {
	err := os.MkdirAll(name, 0755)
	if err != nil {
		panic(err)
	}
}

func installComposer() {
	// todo
}

type projectConfig struct {
	Name string
}

func extractFile(name string, config *projectConfig) error {

	dst := path.Base(name)

	dstFile, err := os.Create(dst)
	if err != nil {
		return err
	}

	if strings.HasSuffix(dst, ".php") || strings.HasSuffix(dst, ".json") {
		templateString, err := skel.ReadFile(name)
		if err != nil {
			return err
		}

		t := template.Must(template.New(name).Parse(string(templateString)))
		err = t.Execute(dstFile, config)
		if err != nil {
			return err
		}
	} else {
		data, err := skel.Open(name)
		if err != nil {
			return err
		}
		_, err = io.Copy(dstFile, data)
		if err != nil {
			return err
		}
	}

	return nil
}

func extractDirectory(name string, config *projectConfig) error {
	dir, err := skel.ReadDir(name)
	if err != nil {
		return err
	}
	for _, f := range dir {
		if f.IsDir() {
			dst := strings.ReplaceAll(f.Name(), "template", "./")
			if dst == "./" {
				err := extractDirectory(f.Name(), config)
				if err != nil {
					return err
				}
				return nil
			}
			err := os.MkdirAll(dst, 0750)
			if err != nil {
				return err
			}

			err = os.Chdir(dst)
			if err != nil {
				return err
			}

			err = extractDirectory(path.Join(name, f.Name()), config)
			if err != nil {
				return err
			}

			err = os.Chdir("..")
			if err != nil {
				return err
			}
		} else {
			err := extractFile(path.Join(name, f.Name()), config)
			if err != nil {
				return err
			}
		}
	}

	return nil
}

func createSkeletonFiles(name string) error {
	config := &projectConfig{Name: name}

	err := extractDirectory(".", config)
	if err != nil {
		return err
	}

	return nil
}

func Execute(args []string, options map[string]string, logger *zap.Logger) int {
	name := args[0]

	createProjectFolder(name)
	err := os.Chdir(name)
	if err != nil {
		logger.Fatal("Failed to enter project directory", zap.Error(err))
		return 1
	}
	err = createSkeletonFiles(name)
	if err != nil {
		logger.Fatal("Failed to create project", zap.Error(err))
		return 1
	}

	return 0
}
