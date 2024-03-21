package auth

type CreatePermissions struct {
	Mode   Mode `json:"mode"`
	Limits struct {
		User   int `json:"user"`
		Role   int `json:"role"`
		Global int `json:"global"`
	} `json:"limits"`
	Users      []UserId `json:"users"`
	Roles      []Role   `json:"roles"`
	TimeToLive uint64   `json:"ttl"`
}
