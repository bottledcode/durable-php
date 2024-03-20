package glue

import (
	"fmt"
	"regexp"
	"strings"
)

type IdKind string

const (
	Activity      IdKind = "activity"
	Entity        IdKind = "entity"
	Orchestration IdKind = "orchestration"
)

// subjects

type Subject struct {
	id *StateId
}

func fromStateId(id StateId) Subject {
	return Subject{
		id: &id,
	}
}

func (subj *Subject) String() string {
	pieces := strings.Split(string(subj.id.Kind)+":"+subj.id.Id, ":")
	reg := regexp.MustCompile(`[^a-zA-Z0-9._-]`)
	for i := range pieces {
		pieces[i] = reg.ReplaceAllString(pieces[i], "")
	}

	return strings.Join(pieces, ".")
}

func (subj *Subject) Bucket() string {
	return strings.ReplaceAll(subj.String(), ".", "_")
}

// state ids

type StateId struct {
	Id   string
	Kind IdKind
}

func fromEntityId(entity *EntityId) *StateId {
	return &StateId{
		Id:   entity.String(),
		Kind: Entity,
	}
}

func fromOrchestrationId(id *OrchestrationId) *StateId {
	return &StateId{
		Id:   id.String(),
		Kind: Orchestration,
	}
}

func fromActivityId(id *ActivityId) *StateId {
	return &StateId{
		Id:   id.String(),
		Kind: Activity,
	}
}

func (id StateId) ToSubject() *Subject {
	return &Subject{
		id: &id,
	}
}

func (id StateId) String() string {
	return fmt.Sprintf("%s:%s", id.Kind, id.Id)
}

func (id StateId) Name() string {
	if before, _, found := strings.Cut(id.Id, ":"); found {
		return before
	}

	return string(Activity)
}

func (id StateId) ToEntityId() (*EntityId, bool) {
	if id.Kind != Entity {
		return nil, false
	}

	parts := strings.Split(id.Id, ":")

	return &EntityId{
		Name: parts[0],
		Id:   parts[1],
	}, true
}

func (id StateId) ToOrchestrationId() (*OrchestrationId, bool) {
	if id.Kind != Orchestration {
		return nil, false
	}

	parts := strings.Split(id.Id, ":")

	return &OrchestrationId{
		InstanceId:  parts[0],
		ExecutionId: parts[1],
	}, true
}

func (id StateId) toActivityId() (*ActivityId, bool) {
	if id.Kind != Activity {
		return nil, false
	}

	return &ActivityId{Id: id.Id}, true
}

func ParseStateId(str string) *StateId {
	parts := strings.Split(str, ":")
	switch parts[0] {
	case string(Activity):
		return (&ActivityId{Id: parts[1]}).ToStateId()
	case string(Entity):
		return (&EntityId{Name: parts[1], Id: parts[2]}).ToStateId()
	case string(Orchestration):
		return (&OrchestrationId{InstanceId: parts[1], ExecutionId: parts[2]}).ToStateId()
	}

	panic("Unknown state Id type")
}

// entity ids

type EntityId struct {
	Name string
	Id   string
}

func (id *EntityId) String() string {
	return fmt.Sprintf("%s:%s", id.Name, id.Id)
}

func (id *EntityId) ToStateId() *StateId {
	return fromEntityId(id)
}

// activity ids

type ActivityId struct {
	Id string
}

func (id *ActivityId) ToStateId() *StateId {
	return fromActivityId(id)
}

func (id *ActivityId) String() string {
	return id.Id
}

// orchestration ids

type OrchestrationId struct {
	InstanceId  string
	ExecutionId string
}

func (id *OrchestrationId) String() string {
	return fmt.Sprintf("%s:%s", id.InstanceId, id.ExecutionId)
}

func (id *OrchestrationId) ToStateId() *StateId {
	return fromOrchestrationId(id)
}
