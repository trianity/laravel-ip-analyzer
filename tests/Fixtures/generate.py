"""Original MIT-licensed synthetic MMDB generator, revision 1. Offline, Python 3."""
import ipaddress
from pathlib import Path

def header(kind, size):
    extra = b''
    if size >= 29:
        extra = bytes([size - 29])
        size = 29
    return bytes([(kind << 5 if kind < 8 else 0) | size]) + (bytes([kind - 7]) if kind >= 8 else b'') + extra

def encode(value, kind=None):
    if isinstance(value, dict):
        return header(7, len(value)) + b''.join(encode(k) + encode(v) for k, v in value.items())
    if isinstance(value, list):
        return header(11, len(value)) + b''.join(encode(v) for v in value)
    if isinstance(value, str):
        data = value.encode()
        return header(2, len(data)) + data
    data = value.to_bytes(max(1, (value.bit_length() + 7) // 8), 'big')
    return header(kind or 6, len(data)) + data

def write(name, dbtype, record, epoch=1700000000):
    nodes = [[None, None]]
    for ip in ['8.8.8.8', '2606:4700:4700::1111', '192.0.2.1', '2001:db8::1']:
        bits = f'{int(ipaddress.ip_address(ip)):0128b}'
        index = 0
        for offset, bit in enumerate(bits):
            side = int(bit)
            if offset == 127:
                nodes[index][side] = 'record'
            else:
                if nodes[index][side] is None:
                    nodes[index][side] = len(nodes)
                    nodes.append([None, None])
                index = nodes[index][side]
    count = len(nodes)
    tree = b''.join((count if p is None else count + 16 if p == 'record' else p).to_bytes(3, 'big') for node in nodes for p in node)
    metadata = {
        'node_count': count, 'record_size': 24, 'ip_version': 6,
        'database_type': dbtype, 'languages': ['en'],
        'binary_format_major_version': 2, 'binary_format_minor_version': 0,
        'build_epoch': epoch, 'description': {'en': 'Synthetic test data'},
    }
    # Metadata has specific integer widths.
    meta = header(7, len(metadata))
    for k, v in metadata.items():
        meta += encode(k) + encode(v, 9 if k == 'build_epoch' else 5 if k in ['record_size', 'ip_version', 'binary_format_major_version', 'binary_format_minor_version'] else None)
    Path(__file__).with_name(name + '.mmdb').write_bytes(tree + bytes(16) + encode(record) + b'\xab\xcd\xefMaxMind.com' + meta)

write('country', 'GeoLite2-Country', {'country': {'iso_code': 'HU'}, 'registered_country': {'iso_code': 'US'}})
write('country-next', 'GeoLite2-Country', {'country': {'iso_code': 'DE'}}, 1700086400)
write('registered-only', 'GeoLite2-Country', {'registered_country': {'iso_code': 'US'}})
write('asn', 'GeoLite2-ASN', {'autonomous_system_number': 64512, 'autonomous_system_organization': 'Synthetic Network'})
write('wrong-type', 'GeoIP2-City', {'country': {'iso_code': 'HU'}})
