(() => {
const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
const csrf=window.MEMOIR.csrf;
let current=null, filterFolder='', pinnedOnly=false, saveTimer=null;
let draftStyle={icon:'fa-note-sticky',color:'#6F5EE8'};

async function api(action, opts={}) {
  const headers=opts.headers||{};
  if((opts.method||'GET')!=='GET') headers['X-CSRF-Token']=csrf;
  if(opts.body && !(opts.body instanceof FormData)) headers['Content-Type']='application/json';
  const res=await fetch(`api.php?action=${encodeURIComponent(action)}${opts.query||''}`, {...opts,headers});
  const data=await res.json();
  if(!res.ok || data.ok===false) throw new Error(data.message||'Request failed');
  return data;
}
const escapeHtml=s=>(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function stripHtml(html){const d=document.createElement('div');d.innerHTML=html||'';return d.textContent||''}
function fmtDate(v){try{return new Date(v.replace(' ','T')).toLocaleDateString(undefined,{month:'short',day:'numeric'})}catch{return''}}

async function loadNote(id){
  const d=await api('note',{query:`&id=${id}`}); current=d.note; draftStyle={icon:current.icon||'fa-note-sticky',color:current.color||'#6F5EE8'};
  $('#emptyState').classList.add('hidden'); $('#editorView').classList.remove('hidden');
  $('#noteTitle').value=current.title||''; $('#noteContent').innerHTML=current.content||'';
  $('#crumbFolder').textContent=current.folder_name||'Unfiled'; $('#updatedAt').textContent=`Updated ${fmtDate(current.updated_at)}`;
  $('#pinNote').classList.toggle('active',current.is_pinned==1); updateWords();
  $$('.note-card').forEach(x=>x.classList.toggle('active',x.dataset.id==id));
}
async function refreshList(){
  const q=$('#globalSearch').value.trim();
  let query=`&q=${encodeURIComponent(q)}`;
  if(filterFolder!=='') query+=`&folder=${encodeURIComponent(filterFolder)}`;
  if(pinnedOnly) query+='&pinned=1';
  const d=await api('search',{query}); renderNotes(d.notes);
}
function renderNotes(notes){
  $('#listCount').textContent=`${notes.length} notes`;
  $('#noteList').innerHTML=notes.map(n=>`<button class="note-card ${current&&current.id==n.id?'active':''}" data-id="${n.id}" data-folder="${n.folder_id??''}" data-pinned="${n.is_pinned}">
    <div class="note-card-top"><i class="fa-solid ${escapeHtml(n.icon)}" style="color:${escapeHtml(n.color||'#6F5EE8')}"></i>${n.is_pinned==1?'<i class="fa-solid fa-thumbtack pin-mini"></i>':''}</div>
    <strong>${escapeHtml(n.title)}</strong><p>${escapeHtml(stripHtml(n.content).slice(0,115))}</p>
    <div class="note-meta"><span>${escapeHtml(n.folder_name||'Unfiled')}</span><time>${fmtDate(n.updated_at)}</time></div></button>`).join('');
}
function queueSave(){
  if(!current)return; $('#saveStatus').textContent='Saving…'; clearTimeout(saveTimer); saveTimer=setTimeout(saveNote,650);
}
async function saveNote(){
  if(!current)return;
  const body={
    id:current.id, folder_id:current.folder_id??'', title:$('#noteTitle').value,
    content:$('#noteContent').innerHTML, icon:draftStyle.icon,color:draftStyle.color,is_pinned:current.is_pinned
  };
  try{await api('save-note',{method:'POST',body:JSON.stringify(body)});$('#saveStatus').textContent='Saved';await refreshList()}
  catch(e){$('#saveStatus').textContent='Save failed'}
}
$('#noteList').addEventListener('click',e=>{const c=e.target.closest('.note-card');if(c)loadNote(c.dataset.id)});
$('#newNote').onclick=async()=>{const d=await api('create-note',{method:'POST',body:JSON.stringify({folder_id:filterFolder||null})});await refreshList();await loadNote(d.id);$('#noteTitle').focus()};
$('#noteTitle').addEventListener('input',queueSave); $('#noteContent').addEventListener('input',()=>{queueSave();updateWords()});
$('#pinNote').onclick=()=>{if(!current)return;current.is_pinned=current.is_pinned==1?0:1;$('#pinNote').classList.toggle('active',current.is_pinned==1);queueSave()};
$('#deleteNote').onclick=async()=>{if(!current||!confirm('Delete this note permanently?'))return;await api('delete-note',{method:'POST',body:JSON.stringify({id:current.id})});current=null;$('#editorView').classList.add('hidden');$('#emptyState').classList.remove('hidden');await refreshList()};

$$('.nav-item').forEach(b=>b.onclick=()=>{filterFolder=b.dataset.folder??'';pinnedOnly=b.dataset.pinned==='1';$$('.nav-item,.folder-item').forEach(x=>x.classList.remove('active'));b.classList.add('active');$('#listTitle').textContent=pinnedOnly?'Pinned':'All notes';refreshList()});
$('#folderList').addEventListener('click',e=>{const b=e.target.closest('.folder-item');if(!b)return;filterFolder=b.dataset.folder;pinnedOnly=false;$$('.nav-item,.folder-item').forEach(x=>x.classList.remove('active'));b.classList.add('active');$('#listTitle').textContent=b.querySelector('span').textContent;refreshList()});

let searchTimer; $('#globalSearch').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(refreshList,220)});
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();$('#globalSearch').focus();$('#globalSearch').select()}if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='n'){e.preventDefault();$('#newNote').click()}});

$$('.toolbar [data-cmd]').forEach(b=>b.onclick=()=>{document.execCommand(b.dataset.cmd,false,b.dataset.value||null);$('#noteContent').focus();queueSave()});
$$('.toolbar [data-block]').forEach(b=>b.onclick=()=>{document.execCommand('formatBlock',false,b.dataset.block);$('#noteContent').focus();queueSave()});
$('#insertLink').onclick=()=>{const u=prompt('Paste URL');if(u)document.execCommand('createLink',false,u)};
$('#insertImage').onclick=()=>$('#imageInput').click(); $('#imageInput').onchange=e=>e.target.files[0]&&uploadImage(e.target.files[0]);

$('#noteContent').addEventListener('paste',e=>{
  const item=[...(e.clipboardData?.items||[])].find(i=>i.type.startsWith('image/'));
  if(item){e.preventDefault();const f=item.getAsFile(); if(f) uploadImage(f);}
});
$('#noteContent').addEventListener('dragover',e=>{e.preventDefault()});
$('#noteContent').addEventListener('drop',e=>{const f=[...(e.dataTransfer?.files||[])].find(x=>x.type.startsWith('image/'));if(f){e.preventDefault();uploadImage(f)}});
async function uploadImage(file){
  const fd=new FormData();fd.append('image',file);
  try{const d=await api('upload',{method:'POST',body:fd});document.execCommand('insertImage',false,d.url);queueSave()}catch(e){alert(e.message)}
}
function updateWords(){const t=$('#noteContent').innerText.trim();$('#wordCount').textContent=`${t?t.split(/\s+/).length:0} words`}

function openModal(id){$(id).classList.remove('hidden')} function closeModal(m){m.classList.add('hidden')}
$$('[data-close]').forEach(b=>b.onclick=()=>closeModal(b.closest('.modal-backdrop')));
$$('.modal-backdrop').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)closeModal(m)}));

let folderIcon='fa-folder',folderColor='#6F5EE8';
$('#addFolder').onclick=()=>openModal('#folderModal');
$('#folderIcons').onclick=e=>{const b=e.target.closest('button');if(!b)return;folderIcon=b.dataset.icon;$$('#folderIcons button').forEach(x=>x.classList.remove('selected'));b.classList.add('selected')};
$('#folderColors').onclick=e=>{const b=e.target.closest('button');if(!b)return;folderColor=b.dataset.color;$$('#folderColors button').forEach(x=>x.classList.remove('selected'));b.classList.add('selected')};
$('#saveFolder').onclick=async()=>{const name=$('#folderName').value.trim();if(!name)return;const d=await api('folder',{method:'POST',body:JSON.stringify({name,icon:folderIcon,color:folderColor})});$('#folderList').insertAdjacentHTML('beforeend',`<button class="folder-item" data-folder="${d.id}"><i class="fa-solid ${d.icon}" style="color:${d.color}"></i><span>${escapeHtml(d.name)}</span><span class="count">0</span></button>`);$('#folderName').value='';closeModal($('#folderModal'))};

$('#noteStyle').onclick=()=>openModal('#styleModal');
$('#noteIcons').onclick=e=>{const b=e.target.closest('button');if(!b)return;draftStyle.icon=b.dataset.icon;queueSave();$$('#noteIcons button').forEach(x=>x.classList.remove('selected'));b.classList.add('selected')};
$('#noteColors').onclick=e=>{const b=e.target.closest('button');if(!b)return;draftStyle.color=b.dataset.color;queueSave();$$('#noteColors button').forEach(x=>x.classList.remove('selected'));b.classList.add('selected')};

$('#settingsBtn').onclick=()=>openModal('#settingsModal');
$('#saveSettings').onclick=async()=>{
  const body={app_name:$('#setAppName').value,smtp_host:$('#setSmtpHost').value,smtp_port:$('#setSmtpPort').value,smtp_security:$('#setSmtpSecurity').value,smtp_user:$('#setSmtpUser').value,smtp_pass:$('#setSmtpPass').value,smtp_from:$('#setSmtpFrom').value};
  await api('settings',{method:'POST',body:JSON.stringify(body)});closeModal($('#settingsModal'));location.reload();
};
})();
