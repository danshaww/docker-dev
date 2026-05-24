<img src="../images/proxmox.png" width="30" align="right"/>

- All services hosted in my environment are running on virtual machines
- All virtual machines in my environment are hosted on Proxmox
- Virtual machines are deployed via one of my prebuilt Windows & Linux templates
### Proxmox Hosts

| Server     | Location   | System                   | CPU      | Memory | Storage                             |
| ---------- | ---------- | ------------------------ | -------- | ------ | ----------------------------------- |
| ldnpveprd1 | London     | Lenovo P330 Tiny         | i7 8700T | 64GB   | 2x2TB NVME<br>ZFS Mirror            |
| mcrpveprd1 | Manchester | Dell Optiplex 7060 Micro | i5 8500T | 32GB   | 500GB NVME<br>500GB SATA<br>4TB HDD |

## Components

### Management
Proxmox management comprises of the following:
- GUI driven monitoring, configuration & update installation
- Custom scripts - used to perform a range of actions (create, archive, etc)

### Tagging

In my environment, tags are entirely used for organisation of VM's in the tag view, virtual machines will be tagged with one of the following:
- core-infrastructure (run's 24/7)
- lab-infrastructure (testing only,  can be shut down, no monitoring)
- templates (vm templates)

