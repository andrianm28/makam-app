# RBAC and Record Scope Matrix — v0.2

| Capability | Customer/Family | Case Manager | Operator | Vendor | Admin | Finance/Issuer/Auditor |
|---|---:|---:|---:|---:|---:|---:|
| Public directory | Yes | Yes | Yes | Yes | Yes | Yes |
| Create At-Need intake | Own | Assist | No | No | Assist | No |
| Manage FuneralCase/tasks | Limited own view | Assigned cases | Assigned input | Assigned work only | Yes | Audit/read subset |
| Confirm availability | No | Record evidence | Assigned cemetery | No | Yes/fallback | No |
| Hold/reserve plot | Request | Assigned action | Assigned authority | No | Privileged | Read/audit |
| Override plot status | No | No | Restricted | No | Privileged only | Audit |
| Quote/open payment | Accept only | Prepare/request | No | No | Authorized | Read/review |
| Restricted documents | Own/purpose | Assigned/purpose | Explicit need only | No default | Authorized | No default |
| Issue/revoke certificate | No | Request | If issuing authority | No | Policy dependent | Dedicated issuer role |
| Memorial edit/publish | Authorized family | No | Policy-dependent | No | Moderation | Audit/privacy |
| Vendor work/evidence | View own outcome | Coordinate | View relevant | Own | Yes | Read |
| Payout/refund | No | No | No | View own | Restricted | Dedicated finance |
| Feature/capability gate | No | No | No | No | Dedicated privileged | Approval/audit |

Exact roles depend on K1/K2. Query-level scope is mandatory.
