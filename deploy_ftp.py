#!/usr/bin/env python3
"""Upload files to Hostinger via FTP."""
import ftplib

FTP_HOST = '82.25.83.117'
FTP_USER = 'u716917981.ManaAgent'
FTP_PASS = 'ConnectPhi@369'
FTP_DIR = '.'

FILES = [
    '/Users/mana/Herm/Projects/03-Work/QR-Track/config.php',
    '/Users/mana/Herm/Projects/03-Work/QR-Track/index.php',
    '/Users/mana/Herm/Projects/03-Work/QR-Track/generate_image.php',
]

ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)
ftp.cwd(FTP_DIR)
print('Connected. CWD:', ftp.pwd())

for local_path in FILES:
    remote_name = local_path.rsplit('/', 1)[-1]
    with open(local_path, 'rb') as f:
        ftp.storbinary(f'STOR {remote_name}', f)
    print(f'Uploaded: {remote_name}')

ftp.quit()
print('All uploads complete.')