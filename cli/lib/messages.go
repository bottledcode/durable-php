package lib

import "time"

type EventMessage struct {
	Destination string    `json:"destination"`
	ReplyTo     string    `json:"replyTo"`
	ScheduleAt  time.Time `json:"scheduleAt"`
	EventId     string    `json:"eventId"`
	Event       string    `json:"event"`
	EventType   string    `json:"eventType"`
	TargetType  string    `json:"targetType"`
}
