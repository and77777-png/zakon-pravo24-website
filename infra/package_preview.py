"""Build a deterministic, public-files-only preview artifact; never run application code."""
import argparse,gzip,hashlib,io,json,pathlib,re,tarfile

def build(root,source,out):
    if not re.fullmatch('[0-9a-f]{40}',source):raise ValueError('EXACT_SOURCE_REQUIRED')
    blobs={}
    for p in sorted(root.rglob('*')):
        if p.is_symlink():raise ValueError('SYMLINK_DENIED')
        if p.is_file():
            name=p.relative_to(root).as_posix()
            if any(x.startswith('.') for x in pathlib.PurePosixPath(name).parts):raise ValueError('HIDDEN_FILE_DENIED')
            blobs['public/'+name]=p.read_bytes()
    manifest={'repo':'and77777-png/zakon-pravo24-website','environment':'preview','source_path':'infra/preview-placeholder/public','source_sha':source,'files':{n:hashlib.sha256(b).hexdigest() for n,b in blobs.items()}}
    blobs['release-manifest.json']=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
    buffer=io.BytesIO()
    with gzip.GzipFile(fileobj=buffer,mode='wb',mtime=0,filename='') as zipped:
        with tarfile.open(fileobj=zipped,mode='w') as archive:
            for name,blob in sorted(blobs.items()):
                info=tarfile.TarInfo(name);info.size=len(blob);info.mode=0o640;info.mtime=0
                archive.addfile(info,io.BytesIO(blob))
    data=buffer.getvalue();out.write_bytes(data)
    return {'source_sha':source,'archive_sha256':hashlib.sha256(data).hexdigest(),'files':manifest['files'],'bytes':len(data)}

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--root',type=pathlib.Path,required=True);p.add_argument('--source',required=True);p.add_argument('--output',type=pathlib.Path,required=True);a=p.parse_args()
    print(json.dumps(build(a.root,a.source,a.output),indent=2))
