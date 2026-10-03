# V1 input classification

The list is an explicit, conservative package policy, reviewed against the IANA
[IPv4](https://www.iana.org/assignments/iana-ipv4-special-registry/) and
[IPv6](https://www.iana.org/assignments/iana-ipv6-special-registry/) special-purpose
registries on 2026-10-03. It is not fetched at runtime. Globally reachable exceptions
inside these special-purpose blocks are excluded too.

IPv4 excluded CIDRs:

- 0.0.0.0/8: unspecified/this-network
- 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16: private
- 100.64.0.0/10: shared/CGNAT
- 127.0.0.0/8: loopback
- 169.254.0.0/16: link local
- 192.0.0.0/24: protocol assignments, including anycast and discovery exceptions
- 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24: documentation
- 192.31.196.0/24, 192.175.48.0/24: AS112 services
- 192.52.193.0/24: AMT
- 192.88.99.0/24: deprecated 6to4/service relay range
- 198.18.0.0/15: benchmarking
- 224.0.0.0/4: multicast
- 240.0.0.0/4: reserved, including limited broadcast

IPv6 accepts only 2000::/3 for potential database lookup, excluding:

- 2001::/23: protocol assignments, including Teredo, benchmarking, anycast,
  AMT/AS112, ORCHID and DET subranges
- 2001:db8::/32 and 3fff::/20: documentation
- 2002::/16: 6to4
- 2620:4f:8000::/48: AS112 service

The global-unicast gate also excludes unspecified, loopback, IPv4-compatible,
translation prefixes 64:ff9b::/96 and 64:ff9b:1::/48, discard/dummy 100:: prefixes,
5f00::/16 SRv6, unique-local, link/site-local, multicast and other currently
non-global-unicast space. No embedded-address interpretation is attempted for
NAT64, Teredo or 6to4.

Exception: ::ffff:0:0/96 mapped input is normalized to ordinary IPv4 first and uses
the IPv4 policy. Thus ::ffff:8.8.8.8 is eligible and ::ffff:127.0.0.1 is NonPublic.
This classification is not a routing test or security verdict.
