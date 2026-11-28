This is a document detailing all scheduled backup & update installation jobs.

| Job                                                | Time  | Cadence              | Server           | Log Location  |
| -------------------------------------------------- | ----- | -------------------- | ---------------- | ------------- |
|                                                    |       |                      |                  |               |
| LDN Rsync App Backup (Test)                        | 10:00 | Daily                | ldnnasprd1       | TrueNAS       |
|                                                    |       |                      |                  |               |
| LDN Proxmox Daily                                  | 23:00 | Daily                | ldnpveprd1       | Proxmox       |
| LDN Proxmox Monthly                                | 00:00 | 1st of Month         | ldnpveprd1       | Proxmox       |
| Cloud Application Sync (Azure)                     | 00:00 | Daily                | ldnnasprd1       | TrueNAS       |
| LDN Proxmox Weekly                                 | 01:00 | Sunday Only (Weekly) | ldnpveprd1       | Proxmox       |
| MCR Proxmox Weekly                                 | 01:00 | Sunday Only (Weekly) | mcrpveprd1       | Proxmox       |
| MCR Proxmox Daily                                  | 01:00 | Daily                | mcrpveprd1       | Proxmox       |
| LDN ZFS Data Snapshot                              | 01:00 | Daily                | ldnnasprd1       | TrueNAS       |
| LDN ZFS Data Replication (Local)                   | 01:00 | Daily                | ldnnasprd1       | TrueNAS       |
| LDN ZFS Data Replication (MCR)                     | 01:00 | Daily (Disabled)     | ldnnasprd1       | TrueNAS       |
| LDN ZFS App Snapshot                               | 02:00 | Daily                | ldnnasprd1       | TrueNAS       |
| LDN ZFS App Replication (MCR)                      | 02:00 | Daily                | ldnnasprd1       | TrueNAS       |
| LDN Rsync App Backup (Prod) THIS NEEDS TO BE MOVED | 02:00 | Daily                | ldnnasprd1       | TrueNAS       |
| Global Watchtower Updates                          | 02:00 | Daily                | All Docker Hosts | Discord       |
| Cloud Data Sync (Azure)                            | 04:00 | Daily                | ldnnasprd1       | TrueNAS       |
| Global Server Updates                              | 04:01 | Daily                | All Servers      | Gitea/Discord |
|                                                    |       |                      |                  |               |
