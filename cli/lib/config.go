package lib

import (
	_ "embed"
	"encoding/json"
	"os"
	"path/filepath"
)

//go:embed defaultConfiguration.json
var defaultConfig string

type BillingUnit struct {
	FreeLimit int `json:"freeLimit"`
	MaxFree   int `json:"maxFree"`
	Cost      int `json:"cost"`
	Limit     int `json:"limit"`
}

type Config struct {
	Stream    string `json:"project"`
	Bootstrap string `json:"bootstrap"`
	Nat       struct {
		Url      string `json:"url"`
		Internal bool   `json:"embeddedServer"`
		Jwt      string `json:"jwt,omitempty"`
		Nkey     string `json:"nkey,omitempty"`
		Tls      struct {
			Ca         string `json:"ca,omitempty"`
			ClientCert string `json:"clientCert,omitempty"`
			KeyFile    string `json:"keyFile,omitempty"`
		} `json:"tls,omitempty"`
	} `json:"nats,omitempty"`
	HistoryRetentionDays int `json:"historyRetentionDays"`
	Extensions           struct {
		Billing struct {
			Enabled bool `json:"enabled"`
			Listen  bool `json:"listen"`
			Costs   struct {
				Orchestrations BillingUnit `json:"orchestrations"`
				Activities     BillingUnit `json:"activities"`
				Entities       BillingUnit `json:"entities"`
				ObjectStorage  BillingUnit `json:"objectStorage"`
				FileStorage    BillingUnit `json:"fileStorage"`
			} `json:"costs"`
		} `json:"billing,omitempty"`
		Search struct {
			Url         string   `json:"url"`
			Key         string   `json:"key"`
			Collections []string `json:"collections"`
		} `json:"search,omitempty"`
		Authz struct {
			Enabled bool     `json:"enabled"`
			Secrets []string `json:"secrets"`
		} `json:"authz,omitempty"`
	} `json:"extensions,omitempty"`
}

func GetProjectConfig() *Config {
	var config Config
	if _, err := os.Stat("dphp.json"); err != nil {
		err := json.Unmarshal([]byte(defaultConfig), &config)
		if err != nil {
			panic(err)
		}
		return &config
	}

	configData, err := os.ReadFile("dphp.json")
	if err != nil {
		panic(err)
	}

	err = json.Unmarshal(configData, &config)
	if err != nil {
		panic(err)
	}
	return &config
}

func findBootstrap(config *Config) {
	bootstrap := config.Bootstrap
	if bootstrap == "" {
		bootstrap = "src/bootstrap.php"
	}

	cwd, err := os.Getwd()
	if err != nil {
		panic(err)
	} else {
		bootstrap = filepath.Join(cwd, bootstrap)
	}

	config.Bootstrap = bootstrap
}

func ApplyOptions(config *Config, options map[string]string) *Config {
	if options["stream"] != "" {
		config.Stream = options["stream"]
	}

	if options["nats-server"] != "" {
		config.Nat.Url = options["nats-server"]
		config.Nat.Internal = false
	}

	if options["bootstrap"] != "" {
		config.Bootstrap = options["bootstrap"]
	}

	findBootstrap(config)

	return config
}
