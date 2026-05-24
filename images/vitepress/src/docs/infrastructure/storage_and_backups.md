<img src="../images/truenas.png" width="30" align="right"/>

# Storage and Backups

My storage & backup infrastructure comprises entirely of TrueNAS servers & the ZFS filesystem.

All management of my TrueNAS infrastructure is performed manually via the web interface.

## Storage Servers

| Server     | Purpose     | Storage                                           |
| ---------- | ----------- | ------------------------------------------------- |
| ldnnasprd1 | Primary NAS | 4TB SATA SSD ZFS MIRROR                           |
| mcrnasprd1 | DR NAS      | 4TB HDD ZFS MIRROR                                |
|            |             |                                                   |

### General File Storage

My standard NAS solution comprises of a set of datasets nested under the 'fs' dataset on my primary storage server.  
This dataset is replicated to my NAS in Manchester on a nightly basis, with ZFS snapshots on both sides retained for 14 days.  

## Backups

In addition to being my NAS, my storage TrueNAS storage solution is used for orchestrating and storing a lot of my backups, this has simplified the replication process where required.  

### Virtual Machine Backups
- All backups taken using inbuild vzdump tools to NFS shares (SMB in Manchester due to issues)
- Daily backups are taken for key VM's only to reduce IO load on disks
- Weekly backups taken on Sunday for all VM's excluding VM templates
- Specific VM's (Docker) have a single backup replicated to the opposite site once a week.

### Data Backups
- Taken using ZFS snapshots on a daily basis
- 14 Daily snapshots retained
- Snapshots exposed and accessible via SMB previous versions
- Managed by TrueNAS

### Application File Backups
- A file based backup of all of my Docker applications is performed via rsync on a nightly basis, this is orchestrated on the TrueNAS side and includes all of my Docker hosts.
- This rsync backup is replicated to cloud storage as a last ditch restore method.

### Manual Configuration Backups
- Unifi OS Server
- TrueNAS Configuration

## Off Site Backups
- All replication is executed on my London backup server
- London TrueNAS runs Rclone tasks to replicate my standard file storage as well as Docker backups to Azure Blob storage on a nightly basis.
- London TrueNAS runs multiple ZFS replication tasks on a nightly basis to replicate my standard file storage as well as Docker backups to Manchester
- London TrueNAS runs a single weekly ZFS replication task to replicate specific VM backups to Manchester (Likely to be depricated.)

