/* WHCSystem v7.0.0. All state, events and selectors stay inside the module root. */
(() => {
  'use strict';
  const initialized = new WeakSet();
  const text = (node) => node.text ?? node.children?.map(text).join(' ') ?? '';
  const fold = (s) => s.toLocaleLowerCase('tr').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replaceAll('ı','i');
  const fieldNames = {
    'yeni parola':'password','parolayi dogrula':'passwordConfirmation','kullanici':'username',
    'ad':'name','yedek adi':'name','snapshot adi':'name','aciklama':'description','etiket':'label',
    'public key':'sshPublicKey','ssh public key':'sshPublicKey','tur':'kind','yedek turu':'kind',
    'hedef disk':'diskId','disk':'diskId','yeni boyut (gb)':'sizeGb','yeni boyut':'sizeGb',
    'onay metni':'confirmation','metin':'consoleText','klavye duzeni':'keyboardLayout',
    'imaj / surum':'templateId','hedef':'sizeGb','not':'description','islem turu':'operationType','gorev no':'taskSearch','tarih':'dateFilter',
    'kapsam':'includeMemory','tutarlilik':'quiesce','gerekce':'reason','islem':'action',
    'ip adresi':'ipAddress','ptr hedefi (fqdn)':'hostname','yeni hedef':'hostname','ttl (saniye)':'ttl',
  };
  const apiErrors = {
    tr:{pending:'İstek işleniyor…',queued:'İşlem kuyruğa alındı. Tamamlanma durumu geçmişten izlenebilir.',
      completed:'İşlem tamamlandı.',failed:'İşlem başarısız oldu.',unknown:'İşlem şu anda tamamlanamadı.',
      unsupported:'Bu işlem mevcut hizmet veya sağlayıcı tarafından desteklenmiyor.',timeout:'İstek zaman aşımına uğradı. İşlemi tekrarlamadan önce geçmişi kontrol edin.',
      confirmation:'Devam etmek için onay kutusunu işaretleyin.',password:'Parolalar eşleşmeli ve en az 12 karakter olmalı.',
      empty:'Henüz veri yok.',denied:'Bu işlem için yetkiniz veya aktif bir hizmetiniz bulunmuyor.',
      session:'Oturum doğrulanamadı. Panelde yeniden oturum açın.',connection:'API bağlantısı kurulamadı.',
      console:'Bu konsol protokolü için uygun görüntüleyici henüz yapılandırılmamış.',generated:'Güvenli parola oluşturuldu.'},
    en:{pending:'Processing request…',queued:'Operation queued. Track its completion in history.',completed:'Operation completed.',failed:'Operation failed.',
      unknown:'The operation could not be completed.',unsupported:'This operation is not supported by this service or provider.',
      timeout:'The request timed out. Check history before retrying.',confirmation:'Select the confirmation checkbox to continue.',
      password:'Passwords must match and contain at least 12 characters.',empty:'No data yet.',denied:'You lack permission or an active service for this operation.',
      session:'Your session could not be verified. Sign in again.',connection:'Could not connect to the API.',console:'A viewer for this console protocol has not been configured.',generated:'A secure password was generated.'},
    de:{pending:'Anfrage wird verarbeitet…',queued:'Vorgang eingereiht. Der Abschluss ist im Verlauf sichtbar.',completed:'Vorgang abgeschlossen.',failed:'Vorgang fehlgeschlagen.',
      unknown:'Der Vorgang konnte nicht abgeschlossen werden.',unsupported:'Dieser Vorgang wird von diesem Dienst oder Anbieter nicht unterstützt.',
      timeout:'Zeitüberschreitung. Prüfen Sie vor einem erneuten Versuch den Verlauf.',confirmation:'Aktivieren Sie das Bestätigungsfeld, um fortzufahren.',
      password:'Die Passwörter müssen übereinstimmen und mindestens 12 Zeichen enthalten.',empty:'Noch keine Daten.',denied:'Es fehlt eine Berechtigung oder ein aktiver Dienst.',
      session:'Die Sitzung konnte nicht geprüft werden. Melden Sie sich erneut an.',connection:'Keine Verbindung zur API.',console:'Für dieses Konsolenprotokoll ist noch kein Betrachter eingerichtet.',generated:'Ein sicheres Passwort wurde erstellt.'},
  };

  function init(root) {
    if (initialized.has(root)||root.dataset.ready==='true') return;
    initialized.add(root);
    const config = JSON.parse(root.querySelector('.whc-config').textContent);
    const language = config.language || 'tr';
    const messages = apiErrors[language] || apiErrors.en;
    Object.assign(messages,({tr:{nextBoot:'İstek kaydedildi; değişiklik bir sonraki açılışta uygulanacak. Henüz doğrulanmadı.',validation:'Alanları ve gerekli onayları kontrol edin.'},en:{nextBoot:'The request was saved. The change applies at the next boot and has not yet been verified.',validation:'Check the fields and required confirmations.'},de:{nextBoot:'Die Anfrage wurde gespeichert. Die Änderung gilt ab dem nächsten Start und ist noch nicht bestätigt.',validation:'Prüfen Sie die Felder und erforderlichen Bestätigungen.'}})[language]||{});
    const state = {overview:config.initialData || {},disks:[],snapshots:[],operations:[],metrics:[],templates:[],network:[],rdns:[],operation:null,range:'1h',operationId:config.operationId || '',busy:false};
    const variants = new Map();
    let visibleVariant, rfb, pollTimer, disposed = false;
    const translate = (s) => config.translations?.[s]?.[language] ?? s;
    const missing = '—';
    const alert = (key,tone='error',code='') => {
      let el = root.querySelector('.whc-alert');
      if (!el) {el=document.createElement('div');el.className='whc-alert';root.prepend(el);}
      el.setAttribute('role',tone==='error'?'alert':'status');el.dataset.tone=tone;
      el.textContent=(messages[key] || messages.unknown)+(code?' ('+code+')':'');
    };
    function actionFor(n) {
      const label=fold(n.label||'');const page=config.page;
      if(config.admin) {
        const commands={createaccount:'admin-create',suspend:'admin-suspend',unsuspend:'admin-unsuspend',terminate:'admin-terminate',changepackage:'admin-package','test connection':'admin-test','test et':'admin-test'};
        if(commands[label])return commands[label];
        if(label==='kaydet'&&page==='settings')return 'admin-settings';
        if(label==='kaydet'&&page==='mapping')return 'admin-map';
      }
      if(label==='parola uret')return 'generate';
      if(label==='tam ekran')return 'fullscreen';
      if(label==='ctrl+alt+del')return 'cad';
      if(label==='baglan')return 'console';
      if(label==='gonder'&&page==='console')return 'console-send';
      if(label==='yenile')return page==='console'?'console-refresh':'refresh';
      if(label==='filtrele')return 'filter';
      if(label==='disa aktar')return 'export';
      if(/^(1sa|6sa|24sa|7g|30g)$/.test(label))return 'range:'+({'1sa':'1h','6sa':'6h','24sa':'24h','7g':'7d','30g':'30d'}[label]);
      if(page==='access'&&label==='guncelle')return 'password';
      if(page==='backupadd'&&/olustur|baslat/.test(label))return 'snapshot-create';
      if(page==='restore'&&/yukle|onayla/.test(label))return 'snapshot-revert';
      if(page==='backupdelete'&&/sil|onayla/.test(label))return 'snapshot-delete';
      if(page==='disk'&&/genislet|uygula|onayla/.test(label))return 'disk';
      if(['start','stop','restart','power'].includes(page)&&/onayla|baslat|durdur|yeniden/.test(label)&&n.link==='logs')return 'power';
      if(page==='reinstall'&&label==='kurulumu baslat')return 'reinstall';
      if(page==='ipattach'&&label==='ag ayarlarini uygula')return 'ipam';
      if(page==='rdnsadd'&&/ptr olustur/.test(label))return 'rdns-create';
      if(page==='rdnsedit'&&label==='kaydet')return 'rdns-update';
      if(page==='deleteconfirm'&&label==='silmeyi onayla')return 'rdns-delete';
      if(['dnsadd','dnsedit','dnsdelete'].includes(page)&&/kaydet|olustur|sil|onayla/.test(label))return 'dns';
      if(['settings','mapping','adminservice'].includes(page)&&n.link==='none')return 'admin';
      return n.link==='none'?'unsupported':'navigate';
    }
    function capability(action) {
      const caps=state.overview.capabilities || {};
      if(action==='admin-map')return !!config.canMap;
      if(action==='admin-settings')return !!config.canConfigure;
      if(action==='admin-test')return !!(config.canTest??config.canConfigure);
      if(action.startsWith('admin-'))return !!config.canCommand;
      return ({power:caps.power,password:caps.password,'snapshot-create':caps.snapshotWrite,
        'snapshot-delete':caps.snapshotWrite,'snapshot-revert':caps.snapshotWrite,disk:caps.disk,
        console:caps.console,reinstall:caps.reinstall,rdns:caps.rdns,dns:caps.dns,ipam:caps.ipam,
        'rdns-create':caps.rdns,'rdns-update':caps.rdns,'rdns-delete':caps.rdns,admin:false,unsupported:false})[action];
    }
    function dynamicText(value,parentName='') {
      if(config.preview)return translate(value);
      const vm=state.overview,billing=config.billing||{};
      if(value==='VPS Enterprise')return billing.productName||missing;
      if(/^(WHMCS|WISECP) · Hizmet/.test(value))return `${config.platform.toUpperCase()} · ${translate('Hizmet')} #${config.serviceId} · ${billing.domain||vm.name||missing}`;
      if(value.startsWith('Hizmetlerim  /'))return `${translate('Hizmetlerim')}  /  ${billing.productName||missing}  /  ${translate(config.screen.pageName.split(' / ')[1])}`;
      if(value==='VMware vCenter  /  TR-IST-01')return vm.providerName||billing.providerLabel||missing;
      if(value==='Çalışıyor')return vm.state ? translate(({POWERED_ON:'Çalışıyor',ON:'Çalışıyor',running:'Çalışıyor',POWERED_OFF:'Durduruldu',OFF:'Durduruldu',stopped:'Durduruldu'})[vm.state]||vm.state) : missing;
      if(value==='6 vCPU')return vm.cpuCount==null?missing:`${vm.cpuCount} vCPU`;
      if(value==='12 GB')return vm.memoryBytes==null?missing:`${Math.round(vm.memoryBytes/1073741824*10)/10} GB`;
      if(value==='130 GB')return state.disks.length?`${Math.round(state.disks.reduce((n,d)=>n+d.sizeBytes,0)/1073741824)} GB`:missing;
      const metric=state.metrics.at(-1),gb=v=>v==null?missing:String(Math.round(v/1073741824*10)/10);
      if(value==='12% kullanım')return metric?.cpuUsageRatio==null?missing:(metric.cpuUsageRatio*100).toFixed(1)+'% '+translate('kullanım');
      if(value==='3.2 / 12 GB')return metric?.memoryUsedBytes==null?missing:gb(metric.memoryUsedBytes)+' / '+gb(vm.memoryBytes)+' GB';
      if(value==='34 / 130 GB')return metric?.diskUsageBytes==null?missing:gb(metric.diskUsageBytes)+' / '+gb(state.disks.reduce((n,d)=>n+d.sizeBytes,0))+' GB';
      if(value==='9g 4s')return vm.uptimeSeconds==null?missing:Math.floor(vm.uptimeSeconds/86400)+'d '+Math.floor(vm.uptimeSeconds%86400/3600)+'h';
      if(value==='administrator')return billing.username||missing;
      if(value.startsWith('Windows Server'))return vm.guestOs||missing;
      if(value==='VMware vCenter')return vm.providerName||vm.providerType||missing;
      if(value==='192.0.2.10')return billing.primaryIp||missing;
      if(value==='2001:db8:100::10')return billing.ipv6||missing;
      if(value==='#10482')return '#'+config.serviceId;
      if(value==='Hizmet #10482')return translate('Hizmet')+' #'+config.serviceId;
      if(value==='22.12.2026')return billing.nextDueDate||missing;
      if(value.startsWith('$24.90'))return billing.amount||missing;
      if(value.includes('sunucu modülü · Örnek veriler'))return `WHCSystem v7.0.0 · ${config.platform.toUpperCase()} · ${translate('Lisans')}: ${vm.licenseActive==null?missing:translate(vm.licenseActive?'Aktif':'Pasif')}`;
      // Figma fixtures are visual references only. Never present sample telemetry,
      // credentials, audit entries, DNS names or completed jobs as live data.
      if(/(?:\d+%|\d+\s*\/\s*\d+|^\d+g \d+s$|TASK-\d+|snapshot-|backup-|vm-10482|example\.(?:com|net)|13\.11\.2026|^\d{2}:\d{2}|^[\d.,]+\s*(?:GB|MB|Mbps|ms)|^\d{2}\.\d{2}\.\d{4})/i.test(value))return missing;
      if(value==='••••••••••••' || value.includes('(gizli)'))return '••••••••••••';
      return translate(value);
    }
    function create(n, parentName='') {
      if(n.field){
        const wrapper=document.createElement('div');wrapper.className=[n.class,n.place].filter(Boolean).join(' ');wrapper.dataset.field=fieldNames[fold(n.field)]||fold(n.field).replace(/[^a-z0-9]+/g,'_');
        if(config.admin&&n.field==='WHC planı')wrapper.dataset.field='adminPlan';
        if(config.page==='restore'&&fold(n.field)==='kaynak')wrapper.dataset.field='snapshotId';
        const name=wrapper.dataset.field;
        const options=config.preview?[]:name==='adminPlan'?(config.plans||[]).map(p=>[p,p]):name==='kind'&&config.page==='backupadd'?[['snapshot',translate('Snapshot')]]:
          name==='diskId'?state.disks.map(d=>[d.id,d.label||d.id]):
          name==='templateId'?state.templates.map(t=>[t.externalId,t.name||t.externalId]):
          name==='snapshotId'?[['',translate(n.field)],...state.snapshots.map(s=>[s.externalId,s.name||s.externalId])]:
          name==='ipAddress'?state.network.map(a=>[a.address,a.address]):
          name==='includeMemory'&&config.page==='backupadd'?[['false',translate('Sistem diski / tüm diskler')],['true',translate('Bellek')]]:
          name==='quiesce'&&config.page==='backupadd'?[['false',translate('Sağlayıcı varsayılanı')],['true','Guest quiesce']]:
          name==='action'&&config.page==='power'?[['start',translate('Başlat')],['shutdown',translate('Güvenli kapat')],['reboot',translate('Yeniden başlat')]]:[];
        const input=document.createElement(options.length?'select':/key|aciklama/.test(fold(n.field))?'textarea':'input');
        input.className='whc-field';input.name=wrapper.dataset.field;input.setAttribute('aria-label',translate(n.field));
        input.id='whc-field-'+n.id.replace(':','-');
        input.addEventListener('input',()=>{input.dataset.dirty='true';});
        input.addEventListener('change',()=>{input.dataset.dirty='true';});
        if(input.tagName==='INPUT')input.type=/parola/.test(fold(n.field))?'password':/boyut|ttl|mtu|vlan/.test(fold(n.field))?'number':'text';
        if(!config.preview&&name==='sizeGb'&&config.page==='disk'){input.type='number';input.min='1';input.step='1';if(state.overview.maxDiskBytes)input.max=String(Math.floor(state.overview.maxDiskBytes/1073741824));}
        input.autocomplete=input.type==='password'?'new-password':'off';
        const placeholder=n.children.find(c=>c.text!==undefined);
        if(placeholder){input.className+=' '+placeholder.class+' '+(placeholder.place||'');}
        if(config.preview)input.placeholder=text(n);else input.placeholder=translate(n.field);
        for(const [value,label]of options){const option=document.createElement('option');option.value=value;option.textContent=label;input.append(option);}
        if(input.type==='password'){input.minLength=12;input.maxLength=128;}
        const readOnly=['mevcut','ek kapasite','ucret','dosya sistemi','hedef vm','mevcut durum','islem','mod','onay','durum','kaynak','kayit','yetki','os ailesi','mimari','ag modu','yedek kontrolu','saklama suresi','zamanlama','depolama','disk kapsami','vm durumu','ek yedek','kayit turu','forward dns kontrolu','reverse zone','mevcut hedef'];
        if(!config.preview&&readOnly.includes(fold(n.field))){input.readOnly=true;input.value=dynamicText(text(n),parentName);}
        if(!config.preview&&options.length)input.readOnly=false;
        const record=state.rdns.find(r=>r.id===config.recordId),snapshot=state.snapshots.find(s=>s.externalId===config.snapshotId);
        if(!config.preview&&['saklama suresi','zamanlama','ek yedek'].includes(fold(n.field))){input.readOnly=true;input.value=messages.unsupported;}
        if(!config.preview&&['SSH anahtarı · Linux'].includes(parentName)){input.readOnly=true;input.value=messages.unsupported;}
        if(!config.preview&&name==='sshPublicKey'&&!(config.page==='reinstall'?state.overview.capabilities?.reinstallSshKey:state.overview.capabilities?.sshKeys)){input.readOnly=true;input.value=messages.unsupported;}
        if(!config.preview&&name==='ttl')input.value=String(record?.ttl??3600);
        if(!config.preview&&name==='hostname'&&config.page==='rdnsedit')input.value=record?.hostname||'';
        if(!config.preview&&fold(n.field)==='mevcut hedef')input.value=record?.hostname||missing;
        if(!config.preview&&name==='snapshotId')input.value=snapshot?.externalId||'';
        if(!config.preview&&fold(n.field)==='kayit')input.value=record?.hostname||snapshot?.name||missing;
        if(!config.preview&&fold(n.field)==='mevcut')input.value=state.disks[0]?.sizeBytes?String(state.disks[0].sizeBytes/1073741824)+' GB':missing;
        if(!config.preview&&fold(n.field)==='ucret'&&config.page==='disk')input.value=translate('Kota dahilinde');
        if(!config.preview&&name==='confirmation')input.placeholder=['reinstall','restore'].includes(config.page)?state.overview.name||translate(n.field):translate('SİL');
        if(!config.preview&&['hedef vm','hostname'].includes(fold(n.field))){input.value=state.overview.name||missing;input.readOnly=true;}
        if(!config.preview&&fold(n.field)==='mevcut durum')input.value=state.overview.state||missing;
        if(!config.preview&&name==='kind'&&config.page==='backupadd'&&!options.length)input.value='snapshot';
        if(!config.preview&&name==='hostname'&&config.page==='reinstall'){input.readOnly=true;input.value=state.overview.name||'';}
        if(config.admin){
          input.readOnly=true;
          input.value=config.adminValues?.[n.field]??missing;
          if(name==='adminPlan'&&options.length&&config.canMap){input.readOnly=false;if(!input.value)input.value=options[0][0];}
          if(input.tagName==='SELECT'&&!config.canMap)input.disabled=true;
        }
        wrapper.append(input);
        // Preserve each actual Figma dropdown icon while replacing only the text prototype with a native control.
        for(const child of n.children||[])if(child.text===undefined)wrapper.append(create(child,n.name));
        wrapper.addEventListener('click',()=>input.focus());return wrapper;
      }
      if(!config.preview){const live=liveContent(n);if(live)return live;}
      const tag=n.tag==='button'?'button':n.tag==='img'?'img':'div';
      const el=document.createElement(n.text!==undefined?'span':tag);
      el.className=[n.class,n.place].filter(Boolean).join(' ');el.dataset.figmaNode=n.id||'';
      if(n.name)el.dataset.layer=n.name;
      if(n.src){el.src=config.assetBase+'/'+n.src;el.alt='';el.setAttribute('aria-hidden','true');}
      if(n.text!==undefined){
        el.textContent=config.admin&&config.page==='settings'&&n.text==='Kaydet'
          ?({tr:'Ayarları aç',en:'Open settings',de:'Einstellungen öffnen'})[language]:dynamicText(n.text,parentName);
        el.dataset.sourceText=n.text;return el;
      }
      if(n.name==='Console viewport'){el.classList.add('whc-console');el.dataset.console='';}
      if(n.link){
        el.type='button';el.classList.add('whc-button-content');const action=actionFor(n);el.dataset.action=action;
        if(action==='navigate')el.dataset.page=n.link;
        if(!config.preview && capability(action)!==undefined && capability(action)!==true){el.disabled=true;el.title=messages.unsupported;}
        el.addEventListener('click',()=>handle(action,n,el));
      }
      for(const child of n.children||[])el.append(create(child,n.name||parentName));
      if(n.name==='Unchecked confirmation'){
        const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.name='confirm';checkbox.className='whc-confirm';checkbox.setAttribute('aria-label',messages.confirmation);
        const decorative=el.firstElementChild;
        if(decorative){checkbox.className+=' '+decorative.className;decorative.replaceWith(checkbox);}else el.prepend(checkbox);
      }
      if(n.name?.startsWith('Period /')){
        el.tabIndex=0;el.setAttribute('role','button');const range=({'1sa':'1h','6sa':'6h','24sa':'24h','7g':'7d','30g':'30d'})[n.name.slice(9)];
        if(range){el.addEventListener('click',()=>{state.range=range;refresh('metrics');});el.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();state.range=range;refresh('metrics');}});}
      }
      return el;
    }
    function findNode(node,predicate){if(predicate(node))return node;for(const child of node.children||[]){const found=findNode(child,predicate);if(found)return found;}return null;}
    function liveContent(n){
      const listNames=['Geri dönüş noktaları','İşlem kayıtları','PTR kayıtları','DNS kayıtları','IPv4 tahsisleri','IPv6 tahsisleri'];
      const infoNames=['Ölçüm bilgileri','Depolama ve kota','Disk envanteri'];
      if(!listNames.includes(n.name)&&!infoNames.includes(n.name)&&!n.name?.startsWith('Resource metric /')&&!n.name?.startsWith('TASK-128')&&n.name!=='Görev akışı'&&n.name!=='Kurulum adımları')return null;
      const card=document.createElement('section');card.className=[n.class,n.place,'whc-live-card'].filter(Boolean).join(' ');
      const title=document.createElement('h2');title.textContent=translate(n.name?.startsWith('TASK-128')?'İşlem detayı':n.name.replace('Resource metric / ',''));card.append(title);
      const dl=rows=>{const list=document.createElement('dl');for(const [label,value]of rows){const term=document.createElement('dt');term.textContent=translate(label);const desc=document.createElement('dd');desc.textContent=value==null?missing:String(value);list.append(term,desc);}card.append(list);};
      const formatBytes=value=>value==null?missing:(Math.round(value/1073741824*100)/100)+' GB';
      if(n.name==='Depolama ve kota'){dl([['Snapshot',state.snapshots.length+' / '+(state.overview.snapshotLimit??missing)],['İmaj yedeği',messages.unsupported],['Kullanım',missing]]);return card;}
      if(n.name==='Disk envanteri'){dl(state.disks.map(d=>[d.label||d.id,formatBytes(d.sizeBytes)]));if(!state.disks.length)card.append(document.createTextNode(messages.empty));return card;}
      if(n.name==='Ölçüm bilgileri'){
        const last=state.metrics.at(-1);
        dl([['CPU',last?.cpuUsageRatio==null?missing:(last.cpuUsageRatio*100).toFixed(1)+'%'],['RAM',formatBytes(last?.memoryUsedBytes)],['Disk',formatBytes(last?.diskUsageBytes)],['Son ölçüm',last?.timestamp?new Date(last.timestamp).toLocaleString(language):missing]]);return card;
      }
      if(n.name.startsWith('Resource metric /')){
        const kind=n.name.split(' / ')[1];
        const series=kind==='CPU'?[['cpuUsageRatio',100,'%']]:kind==='RAM'?[['memoryUsedBytes',1/1073741824,'GB']]:kind==='DİSK'?[['diskReadBytes',1/1048576,'MB/s'],['diskWriteBytes',1/1048576,'MB/s']]:[['networkReceivedBytes',8/1e6,'Mb/s'],['networkTransmittedBytes',8/1e6,'Mb/s']];
        const samples=state.metrics.filter(s=>s.timestamp).sort((a,b)=>new Date(a.timestamp)-new Date(b.timestamp));
        const last=samples.at(-1),canvas=document.createElement('canvas');canvas.className='whc-chart';canvas.setAttribute('role','img');canvas.setAttribute('aria-label',translate(kind)+' · '+state.range);card.append(canvas);
        const values=samples.map(s=>s[series[0][0]]).filter(v=>v!=null&&Number.isFinite(v)).map(v=>v*series[0][1]);
        if(!values.length){canvas.remove();card.append(document.createTextNode(messages.empty));return card;}
        dl([['Anlık',values.at(-1).toFixed(2)+' '+series[0][2]],['Ortalama',(values.reduce((a,b)=>a+b,0)/values.length).toFixed(2)+' '+series[0][2]],['Tepe',Math.max(...values).toFixed(2)+' '+series[0][2]],['Son ölçüm',new Date(last.timestamp).toLocaleString(language)]]);
        requestAnimationFrame(()=>{
          if(!canvas.isConnected)return;const box=canvas.getBoundingClientRect();const scale=devicePixelRatio||1;
          canvas.width=Math.max(1,Math.round(box.width*scale));canvas.height=Math.max(1,Math.round(box.height*scale));
          const ctx=canvas.getContext('2d');ctx.scale(scale,scale);const w=box.width,h=box.height;ctx.clearRect(0,0,w,h);
          const all=series.flatMap(([key,factor])=>samples.map(s=>s[key]==null?null:s[key]*factor)).filter(v=>v!=null&&Number.isFinite(v));
          const max=Math.max(...all,1),minTime=new Date(samples[0].timestamp).getTime(),maxTime=new Date(last.timestamp).getTime();
          const colors=[getComputedStyle(root).getPropertyValue('--color-brand').trim(),'#c3955b'];
          series.forEach(([key,factor],index)=>{ctx.strokeStyle=colors[index];ctx.lineWidth=2;ctx.beginPath();let started=false;for(const sample of samples){const value=sample[key];if(value==null||!Number.isFinite(value)){started=false;continue;}const x=8+(w-16)*(new Date(sample.timestamp).getTime()-minTime)/Math.max(1,maxTime-minTime),y=h-8-(h-16)*value*factor/max;if(started)ctx.lineTo(x,y);else ctx.moveTo(x,y);started=true;}ctx.stroke();});
        });return card;
      }
      if(n.name.startsWith('TASK-128')||['Görev akışı','Kurulum adımları'].includes(n.name)){
        const op=state.operation;if(!op){card.append(document.createTextNode(messages.empty));return card;}
        if(n.name==='Görev akışı'||n.name==='Kurulum adımları')dl((op.steps||[]).map(s=>[s.name,s.state]));
        else dl([['Görev',op.id],['İşlem',op.type],['Durum',op.state],['Tarih',new Date(op.createdAt).toLocaleString(language)],['Yetkili',op.actorId],['Hata',op.errorCode]]);
        return card;
      }
      const table=document.createElement('table'),head=document.createElement('thead'),body=document.createElement('tbody');
      const isHistory=n.name==='İşlem kayıtları',isSnapshots=n.name==='Geri dönüş noktaları',isRdns=n.name==='PTR kayıtları';
      const headers=isHistory?['Görev','İşlem','Tarih','Durum','Yetkili','Detay']:isSnapshots?['Ad / ID','Tür','Tarih','İşlem']:isRdns?['IP adresi','PTR hedefi','TTL','Durum','Detay']:['IP adresi','Durum'];
      const heading=document.createElement('tr');for(const label of headers){const th=document.createElement('th');th.scope='col';th.textContent=translate(label);heading.append(th);}head.append(heading);table.append(head,body);
      let records=isHistory?state.operations:isSnapshots?state.snapshots:isRdns?state.rdns:[];
      if(isHistory&&state.filters){const f=state.filters;records=records.filter(o=>(!f.operationType||o.type.includes(f.operationType))&&(!f.durum||fold(o.state).includes(fold(f.durum)))&&(!f.taskSearch||o.id.includes(f.taskSearch))&&(!f.dateFilter||o.createdAt.startsWith(f.dateFilter)));}
      if(!isSnapshots&&!isHistory&&!isRdns){records=state.network.filter(a=>n.name==='IPv4 tahsisleri'?!a.address.includes(':'):n.name==='IPv6 tahsisleri'?a.address.includes(':'):false).map(a=>({...a,state:state.overview.state||missing}));const ip=n.name==='IPv4 tahsisleri'?config.billing?.primaryIp:n.name==='IPv6 tahsisleri'?config.billing?.ipv6:null;if(!records.length&&ip)records=[{address:ip,state:state.overview.state||missing}];}
      for(const item of records){
        const row=document.createElement('tr');const data=isHistory?[item.id,item.type,new Date(item.createdAt).toLocaleString(language),item.state,item.actorId||missing]:isSnapshots?[item.name||item.externalId,'Snapshot',item.createdAt?new Date(item.createdAt).toLocaleString(language):missing]:isRdns?[item.ipAddress,item.hostname,item.ttl,item.state]:[item.address,item.state];
        for(const value of data){const cell=document.createElement('td');cell.textContent=String(value??missing);row.append(cell);}
        if(isHistory||isSnapshots||isRdns){
          const cell=document.createElement('td');
          const targets=isHistory?['logdetail']:isRdns?['rdnsedit']:['restore','backupdelete'];
          for(const target of targets){
            const source=findNode(config.screen.variants.find(v=>v.tree===undefined?false:v.viewport+':'+v.theme===visibleVariant)?.tree||config.screen.variants[0].tree,x=>x.link===target)
              || findNode(config.screen.variants[0].tree,x=>x.link===(isHistory?'logdetail':'restore'));
            let button;
            if(source){button=create({...source,place:undefined,link:target});button.classList.add('whc-live-action');}
            else{button=document.createElement('button');button.type='button';button.className='whc-live-action';button.textContent=translate(target==='logdetail'?'Detay':target==='restore'?'Geri yükle':'Sil');button.addEventListener('click',()=>handle('navigate',{link:target},button));}
            button.addEventListener('click',()=>{if(isSnapshots)config.snapshotId=item.externalId;else if(isRdns)config.recordId=item.id;else state.operationId=item.id;},{capture:true});cell.append(button);
          }row.append(cell);
        }
        body.append(row);
      }
      if(!records.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=headers.length;cell.textContent=messages.empty;row.append(cell);body.append(row);}
      card.append(table);return card;
    }
    function showVariant() {
      const width=root.getBoundingClientRect().width;
      const viewport=width>=960?'desktop':width>=600?'tablet':'mobile';
      const hostDark=document.documentElement.dataset.theme==='dark'||document.body.dataset.theme==='dark'||document.documentElement.classList.contains('dark');
      const theme=config.theme==='auto'?(hostDark?'dark':'light'):(config.theme||'light');
      root.dataset.theme=theme;
      const key=viewport+':'+theme;
      if(key===visibleVariant)return;
      const values=new Map([...root.querySelectorAll('.whc-field[data-dirty=true]')].map(el=>[el.name,el.value]));
      const confirmed=!!root.querySelector('[name=confirm]:checked');
      for(const el of variants.values())el.remove();variants.clear();
      const variant=config.screen.variants.find(v=>v.viewport===viewport&&v.theme===theme);
      if(!variant)throw new Error('FIGMA_VARIANT_MISSING');
      visibleVariant=key;
      const el=create(variant.tree);el.dataset.variant=variant.id;root.append(el);variants.set(key,el);
      for(const input of el.querySelectorAll('.whc-field'))if(values.has(input.name)){
        const previous=values.get(input.name);
        if(input.tagName!=='SELECT'||[...input.options].some(option=>option.value===previous)){input.value=previous;input.dataset.dirty='true';}
      }
      if(confirmed&&el.querySelector('[name=confirm]'))el.querySelector('[name=confirm]').checked=true;
      visibleVariant=key;
      if(language!=='tr')document.fonts.ready.then(()=>requestAnimationFrame(()=>reflow(el)));
    }
    function reflow(container){
      if(!container.isConnected)return;
      for(const child of container.children)reflow(child);
      if(getComputedStyle(container).display!=='grid')return;
      const computed=getComputedStyle(container);const rows=computed.gridTemplateRows.split(' ').map(parseFloat);
      if(!rows.length||rows.some(n=>!Number.isFinite(n)))return;
      let added=0;
      for(const child of container.children){
        const style=getComputedStyle(child),start=Number(style.gridRowStart)-1,end=Number(style.gridRowEnd)-1;
        if(!Number.isInteger(start)||!Number.isInteger(end)||start<0||end<=start||end>rows.length)continue;
        const available=rows.slice(start,end).reduce((a,b)=>a+b,0),needed=child.getBoundingClientRect().height;
        if(needed>available+.5){const extra=Math.ceil(needed-available);rows[end-1]+=extra;added+=extra;}
      }
      if(added){container.style.gridTemplateRows=rows.map(n=>n+'px').join(' ');if(!container.dataset.variant)container.style.height=(container.getBoundingClientRect().height+added)+'px';}
    }
    function rebuild(){
      visibleVariant=undefined;showVariant();
    }
    async function request(mode,resource,data={}){
      const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),35000);
      try{
        if(config.transport==='wisecp-tool'){
          if(typeof window.request_tool_action!=='function')throw new Error('PLATFORM_BRIDGE_UNAVAILABLE');
          // WISECP masks top-level fields whose names contain secret/password/token in service history.
          // Keep nested credentials under a masked field, rather than an innocently named JSON data field.
          const result=await Promise.race([
            window.request_tool_action('whcsystem','dispatch',{mode,resource,csrf_token:(resource==='admin-test'?config.adminCsrf:config.csrf)||'',key:crypto.randomUUID().replaceAll('-',''),secret_payload:JSON.stringify(data)},{successToast:false}),
            new Promise((_,reject)=>controller.signal.addEventListener('abort',()=>reject(new DOMException('Timed out','AbortError')),{once:true}))
          ]);
          if(!result||result.status==='error'||result.error)throw new Error(result?.code||'PLATFORM_BRIDGE_RESPONSE_INVALID');
          return result;
        }
        const endpoint=new URL(config.endpoint,location.href);
        if(endpoint.origin!==location.origin)throw new Error('CROSS_ORIGIN_ENDPOINT');
        const body=new URLSearchParams({serviceId:String(config.serviceId),mode,resource,csrf:config.csrf||'',key:crypto.randomUUID().replaceAll('-',''),data:JSON.stringify(data),...(config.requestFields||{})});
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,signal:controller.signal,cache:'no-store'});
        const result=await response.json();
        if(!response.ok||result.error||result.status==='error'){const error=new Error(result.code||result.error?.code||'API_REQUEST_FAILED');error.status=response.status;throw error;}
        return result;
      }catch(error){if(error.name==='AbortError')throw new Error('PROVIDER_TIMEOUT');throw error;}finally{clearTimeout(timeout);}
    }
    function report(error){const code=error.message||'API_REQUEST_FAILED';alert(code==='PROVIDER_TIMEOUT'?'timeout':/VALIDATION|CONFIRMATION/.test(code)?'validation':/CAPABILITY|UNSUPPORTED/.test(code)?'unsupported':error.status===401?'session':error.status===403?'denied':'connection','error',code);}
    function fields(){
      const active=variants.get(visibleVariant);const data={};
      for(const input of active.querySelectorAll('.whc-field')){if(input.disabled||input.readOnly)continue;if(!input.reportValidity())throw new Error('VALIDATION_FAILED');data[input.name]=input.value;}
      data.includeMemory=data.includeMemory==='true';data.quiesce=data.quiesce==='true';
      data.confirm=!!active.querySelector('[name=confirm]:checked');
      data.snapshotId=data.snapshotId??config.snapshotId??'';data.diskId=data.diskId||config.diskId||state.disks[0]?.id||'';
      data.recordId=config.recordId||'';
      data.username=data.username||config.billing?.username||'root';return data;
    }
    async function handle(action,n,button){
      if(state.busy)return;
      if(action==='navigate'){
        const url=new URL(location.href);url.searchParams.set('whcPage',n.link);
        if(config.snapshotId)url.searchParams.set('whcSnapshot',config.snapshotId);
        if(config.recordId)url.searchParams.set('whcRecord',config.recordId);
        if(state.operationId)url.searchParams.set('whcOperation',state.operationId);
        location.assign(url);return;
      }
      if(action==='admin-settings'){
        if(config.canConfigure&&config.nativeServerId){
          const url=new URL('configservers.php',location.href);url.searchParams.set('action','manage');url.searchParams.set('id',String(config.nativeServerId));location.assign(url);
        }else alert('unsupported');
        return;
      }
      if(action==='generate'){
        const alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%+-_';
        const secure=new Uint8Array(32);let value='';
        while(value.length<24){crypto.getRandomValues(secure);for(const b of secure){if(b<Math.floor(256/alphabet.length)*alphabet.length)value+=alphabet[b%alphabet.length];if(value.length===24)break;}}
        for(const input of variants.get(visibleVariant).querySelectorAll('input[type=password]')){input.value=value;input.dataset.dirty='true';}
        alert('generated','success');return;
      }
      if(action==='filter'){state.filters=fields();rebuild();return;}
      if(action==='export'){
        const csv=[['ID','Type','State','CreatedAt'],...state.operations.map(o=>[o.id,o.type,o.state,o.createdAt])].map(row=>row.map(value=>'"'+String(value??'').replace(/^[=+@-]/,"'$&").replaceAll('"','""')+'"').join(',')).join('\r\n');
        const url=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='whcsystem-history.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);return;
      }
      if(action==='fullscreen'){try{const target=variants.get(visibleVariant).querySelector('[data-console]');if(document.fullscreenElement)await document.exitFullscreen();else await target.requestFullscreen();}catch{alert('unsupported');}return;}
      if(action==='cad'){if(rfb)rfb.sendCtrlAltDel();else alert('console');return;}
      if(action==='console-send'){if(rfb?.clipboardPasteFrom)rfb.clipboardPasteFrom(fields().consoleText||'');else alert('console');return;}
      if(action.startsWith('range:')){state.range=action.slice(6);await refresh('metrics');return;}
      if(action==='refresh'){await refresh(config.page==='monitor'?'metrics':'overview');return;}
      if(action==='console-refresh'){if(rfb){rfb.disconnect();rfb=null;}action='console';}
      try{
        const data=fields();
        if(config.admin&&['admin-create','admin-suspend','admin-unsuspend','admin-package','admin-terminate','admin-map'].includes(action)){
          data.confirm=window.confirm(({tr:'Bu yönetim işlemini onaylıyor musunuz?',en:'Confirm this administrative action?',de:'Diese Verwaltungsaktion bestätigen?'})[language]+' '+(n.label||'')+' · #'+config.serviceId);
          if(!data.confirm)return;
        }
        if(action==='disk'&&!data.confirm&&!variants.get(visibleVariant).querySelector('[name=confirm]'))data.confirm=window.confirm(translate('Disk genişletme isteğini onaylıyor musunuz?'));
        if(['power','snapshot-delete','snapshot-revert','disk','reinstall','rdns-delete'].includes(action)&&!data.confirm){alert('confirmation');return;}
        if(action==='snapshot-revert'&&(!data.snapshotId||!state.overview.name||data.confirmation!==state.overview.name)){alert('validation');return;}
        if(['snapshot-delete','rdns-delete'].includes(action)&&data.confirmation!==translate('SİL')){alert('validation');return;}
        if(action==='password'&&(data.password?.length<12||data.password!==data.passwordConfirmation)){alert('password');return;}
        if(action==='power')data.action=config.page==='power'?(data.action||'reboot'):({start:'start',stop:'shutdown',restart:'reboot'})[config.page];
        state.busy=true;button.disabled=true;button.setAttribute('aria-busy','true');alert('pending','info');
        const result=await request('mutate',action,data);
        if(action==='console'){await connectConsole(result.data);root.querySelector('.whc-alert')?.remove();return;}
        if(result.completed===true){alert('completed','success');if(action.startsWith('rdns-'))await refresh('rdns');}
        else if(result.data?.id){state.operationId=result.data.id;state.pendingPower=action==='power'?data.action:null;state.pollStarted=Date.now();alert('queued','success');schedulePoll();}
        else{alert('unknown');}
      }catch(error){report(error);}finally{state.busy=false;button.disabled=!config.preview&&capability(action)===false;button.removeAttribute('aria-busy');}
    }
    async function connectConsole(access){
      const target=variants.get(visibleVariant).querySelector('[data-console]');
      if(!access?.url||new Date(access.expiresAt)<=new Date())throw new Error('CONSOLE_EXPIRED');
      if(!['vnc','novnc'].includes(String(access.protocol).toLowerCase()))throw new Error('CONSOLE_PROTOCOL_UNSUPPORTED');
      // The package supplies the official noVNC viewer; connection tokens remain in memory.
      const {default:RFB}=await import('./vendor/novnc/core/rfb.js');
      const url=new URL(access.url);if(url.protocol!=='wss:')throw new Error('CONSOLE_PROTOCOL_UNSUPPORTED');
      target.replaceChildren();rfb=new RFB(target,url.href,{credentials:access.credentials||{}});rfb.scaleViewport=true;rfb.resizeSession=true;
      rfb.addEventListener('securityfailure',()=>alert('console'));
      rfb.addEventListener('disconnect',event=>{if(!event.detail.clean)alert('connection');});
    }
    function schedulePoll(){clearTimeout(pollTimer);pollTimer=setTimeout(async()=>{
      if(disposed||!root.isConnected)return;
      try{const result=await request('read','operation',{id:state.operationId});state.operation={...result.data,steps:result.steps||[]};rebuild();const status=result.data?.state;
        if(['SUCCEEDED','COMPLETED'].includes(status)){
          if(result.data.appliesAtNextBoot){alert('nextBoot','info');return;}
          if(state.pendingPower){const current=await request('read','overview');state.overview=current.data||{};rebuild();const expected=['start','reboot','reset'].includes(state.pendingPower)?['POWERED_ON','ON','running']:['POWERED_OFF','OFF','stopped'];
            if(!expected.includes(state.overview.state)){if(Date.now()-(state.pollStarted||Date.now())>90000){alert('timeout');return;}schedulePoll();return;}}
          alert('completed','success');await refresh('overview');
        }
        else if(['FAILED','FAILED_REQUIRES_ATTENTION','CANCELLED'].includes(status))alert('failed','error',result.data?.errorCode||status);
        else schedulePoll();
      }catch(error){report(error);}
    },3000);}
    async function refresh(resource){
      try{const result=await request('read',resource,{range:state.range,id:state.operationId});state[resource]=result.data|| (resource==='overview'?{}:[]);rebuild();}
      catch(error){report(error);}
    }
    rebuild();
    const resize=new ResizeObserver(showVariant);resize.observe(root);
    root.addEventListener('whc:theme',event=>{config.theme=event.detail;showVariant();});
    window.addEventListener('pagehide',()=>{disposed=true;clearTimeout(pollTimer);resize.disconnect();rfb?.disconnect();},{once:true});
    if(!config.preview){
      if(!config.admin||config.page==='logs'||config.page==='logdetail')refresh('overview');
      if(['overview','disk','reinstall'].includes(config.page))refresh('disks');
      if(['backup','backupadd','restore','backupdelete'].includes(config.page))refresh('snapshots');
      if(config.page==='reinstall')refresh('templates');
      if(['ipv4','ipv6','rdns','rdnsadd','rdnsedit','deleteconfirm'].includes(config.page))refresh('network');
      if(['rdns','rdnsedit','deleteconfirm'].includes(config.page))refresh('rdns');
      if(['monitor','overview'].includes(config.page))refresh('metrics');
      if(config.page==='logs')refresh('operations');
      if(state.operationId&&['logdetail','installprogress'].includes(config.page))schedulePoll();
    }
    root.dataset.ready='true';
  }
  const boot=()=>document.querySelectorAll('.whc-module').forEach(init);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
