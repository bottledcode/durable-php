package lib

import (
	"context"
	"durable_php/config"
	"github.com/typesense/typesense-go/typesense"
	"github.com/typesense/typesense-go/typesense/api"
	"github.com/typesense/typesense-go/typesense/api/pointer"
)

func CreateEntityIndex(ctx context.Context, client *typesense.Client, config *config.Config) error {
	_, err := client.Collection(config.Stream + "_entities").Retrieve(ctx)
	if err == nil {
		return nil
	}

	_, err = client.Collections().Create(ctx, &api.CollectionSchema{
		EnableNestedFields: pointer.True(),
		Fields: []api.Field{
			{
				Name: "name", Type: "string", Facet: pointer.True(),
			},
			{
				Name: "id", Type: "string",
			},
			{
				Name: ".*", Type: "auto",
			},
			{
				Name: ".*_facet", Type: "auto", Facet: pointer.True(),
			},
		},
		Name: config.Stream + "_entities",
	})
	if err != nil {
		return err
	}

	return nil
}

func CreateOrchestrationIndex(ctx context.Context, client *typesense.Client, config *config.Config) error {
	_, err := client.Collection(config.Stream + "_orchestrations").Retrieve(ctx)
	if err == nil {
		return nil
	}

	_, err = client.Collections().Create(ctx, &api.CollectionSchema{
		EnableNestedFields: pointer.True(),
		Fields: []api.Field{
			{
				Name: "execution_id", Type: "string",
			},
			{
				Name: "instance_id", Type: "string", Facet: pointer.True(),
			},
			{
				Name: "id", Type: "string",
			},
			{
				Name: "created_at", Type: "string",
			},
			{
				Name: "custom_status", Type: "string",
			},
			{
				Name: "last_updated_at", Type: "string",
			},
			{
				Name: "runtime_status", Type: "string",
			},
		},
		Name: config.Stream + "_orchestrations",
	})

	return nil
}
