package config

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

type NatConfig struct {
	Url      string    `json:"url"`
	Internal bool      `json:"embeddedServer"`
	Jwt      string    `json:"jwt,omitempty"`
	Nkey     string    `json:"nkey,omitempty"`
	Tls      TlsConfig `json:"tls,omitempty"`
}

type TlsConfig struct {
	Ca         string `json:"ca,omitempty"`
	ClientCert string `json:"clientCert,omitempty"`
	KeyFile    string `json:"keyFile,omitempty"`
}

type BillingConfig struct {
	Enabled bool        `json:"enabled"`
	Listen  bool        `json:"listen"`
	Costs   CostsConfig `json:"costs"`
}

type CostsConfig struct {
	Orchestrations BillingUnit `json:"orchestrations"`
	Activities     BillingUnit `json:"activities"`
	Entities       BillingUnit `json:"entities"`
	ObjectStorage  BillingUnit `json:"objectStorage"`
	FileStorage    BillingUnit `json:"fileStorage"`
}

type SearchConfig struct {
	Url         string   `json:"url"`
	Key         string   `json:"key"`
	Collections []string `json:"collections"`
}

type AuthzConfig struct {
	Enabled bool     `json:"enabled"`
	Secrets []string `json:"secrets"`
}

type ExtensionsConfig struct {
	Billing BillingConfig `json:"billing,omitempty"`
	Search  SearchConfig  `json:"search,omitempty"`
	Authz   AuthzConfig   `json:"authz,omitempty"`
}

type Config struct {
	Stream               string           `json:"project"`
	Bootstrap            string           `json:"bootstrap"`
	Nat                  NatConfig        `json:"nats,omitempty"`
	HistoryRetentionDays int              `json:"historyRetentionDays"`
	Extensions           ExtensionsConfig `json:"extensions,omitempty"`
}

func GetProjectConfig() (*Config, error) {
	var config Config
	err := json.Unmarshal([]byte(defaultConfig), &config)
	if err != nil {
		return nil, err
	}
	if _, err := os.Stat("dphp.json"); err != nil {
		return &config, nil
	}
	configData, err := os.ReadFile("dphp.json")
	if err != nil {
		return nil, err
	}
	err = json.Unmarshal(configData, &config)
	if err != nil {
		return nil, err
	}
	return &config, nil
}

func findBootstrap(config *Config) error {
	bootstrap := config.Bootstrap
	if bootstrap == "" {
		bootstrap = "src/bootstrap.php"
	}
	cwd, err := os.Getwd()
	if err != nil {
		return err
	} else {
		bootstrap = filepath.Join(cwd, bootstrap)
	}
	config.Bootstrap = bootstrap
	return nil
}

func ApplyOptions(config *Config, options map[string]string) (*Config, error) {
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
	err := findBootstrap(config)
	if err != nil {
		return nil, err
	}
	return config, nil
}
