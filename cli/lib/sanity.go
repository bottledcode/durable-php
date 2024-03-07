package lib

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
	pieces := strings.Split(subj.id.id, ":")
	reg := regexp.MustCompile(`[^a-zA-Z0-9._-]`)
	for i := range pieces {
		pieces[i] = reg.ReplaceAllString(pieces[i], "")
	}

	return strings.Join(pieces, ".")
}

// state ids

type StateId struct {
	id   string
	kind IdKind
}

func fromEntityId(entity *entityId) *StateId {
	return &StateId{
		id:   entity.String(),
		kind: Entity,
	}
}

func fromOrchestrationId(id *orchestrationId) *StateId {
	return &StateId{
		id:   id.String(),
		kind: Orchestration,
	}
}

func fromActivityId(id *activityId) *StateId {
	return &StateId{
		id:   id.String(),
		kind: Activity,
	}
}

func (id StateId) toSubject() *Subject {
	return &Subject{
		id: &id,
	}
}

func (id StateId) String() string {
	return fmt.Sprintf("%s:%s", id.kind, id.id)
}

func (id StateId) toEntityId() (*entityId, bool) {
	if id.kind != Entity {
		return nil, false
	}

	parts := strings.Split(id.id, ":")

	return &entityId{
		name: parts[0],
		id:   parts[1],
	}, true
}

func (id StateId) toOrchestrationId() (*orchestrationId, bool) {
	if id.kind != Orchestration {
		return nil, false
	}

	parts := strings.Split(id.id, ":")

	return &orchestrationId{
		instanceId:  parts[0],
		executionId: parts[1],
	}, true
}

func (id StateId) toActivityId() (*activityId, bool) {
	if id.kind != Activity {
		return nil, false
	}

	return &activityId{id: id.id}, true
}

func ParseStateId(str string) *StateId {
	parts := strings.Split(str, ":")
	switch parts[0] {
	case string(Activity):
		return (&activityId{id: parts[1]}).toStateId()
	case string(Entity):
		return (&entityId{name: parts[1], id: parts[2]}).toStateId()
	case string(Orchestration):
		return (&orchestrationId{instanceId: parts[1], executionId: parts[2]}).toStateId()
	}

	panic("Unknown state id type")
}

// entity ids

type entityId struct {
	name string
	id   string
}

func (id *entityId) String() string {
	return fmt.Sprintf("%s:%s", id.name, id.id)
}

func (id *entityId) toStateId() *StateId {
	return fromEntityId(id)
}

// activity ids

type activityId struct {
	id string
}

func (id *activityId) toStateId() *StateId {
	return fromActivityId(id)
}

func (id *activityId) String() string {
	return id.id
}

// orchestration ids

type orchestrationId struct {
	instanceId  string
	executionId string
}

func (id *orchestrationId) String() string {
	return fmt.Sprintf("%s:%s", id.instanceId, id.executionId)
}

func (id *orchestrationId) toStateId() *StateId {
	return fromOrchestrationId(id)
}
