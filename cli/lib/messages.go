package lib

import "time"

type EventMessage struct {
	Destination string    `json:"destination"`
	ReplyTo     string    `json:"replyTo"`
	ScheduleAt  time.Time `json:"scheduleAt"`
	EventId     string    `json:"eventId"`
	Priority    int       `json:"priority"`
	Locks       bool      `json:"locks"`
	IsPoisoned  bool      `json:"isPoisoned"`
	Event       string    `json:"event"`
}
