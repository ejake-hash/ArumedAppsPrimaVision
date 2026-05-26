<template>
  <div class="h-screen bg-white flex flex-col overflow-hidden font-sans text-slate-900">
    <header class="h-14 border-b border-slate-200 flex items-center px-6 justify-between shrink-0">
      <div class="text-sm font-semibold">EMR Auto-Schema Builder</div>
      <div class="text-[11px] font-mono text-slate-400">STATUS: {{ isLoading ? 'PROCESSING' : (generatedSchema?.length ? 'READY' : 'IDLE') }}</div>
    </header>

    <div class="flex-1 flex overflow-hidden">
      <div class="w-72 border-r border-slate-200 p-6 flex flex-col gap-6">
        <div>
          <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Upload Dokumen</label>
          <div @click="triggerFileInput" class="h-32 border border-slate-300 border-dashed rounded flex flex-col items-center justify-center cursor-pointer hover:bg-slate-50 transition-colors">
            <span class="text-xs text-slate-500 font-medium">{{ selectedFile ? selectedFile.name : 'Pilih file (PDF/IMG)' }}</span>
          </div>
          <input type="file" ref="fileInputRef" @change="handleFileChange" class="hidden" accept="application/pdf,image/*" />
        </div>

        <div class="flex flex-col gap-2">
          <button 
            @click="uploadAndGenerateForm" 
            :disabled="isLoading || !selectedFile"
            class="w-full h-10 bg-slate-900 text-white text-xs font-bold uppercase tracking-wide hover:bg-black disabled:bg-slate-200 transition-colors"
          >
            {{ isLoading ? 'Menganalisis...' : 'Ekstrak Skema' }}
          </button>

          <button 
            v-if="generatedSchema?.length > 0"
            @click="saveSchemaToDatabase" 
            class="w-full h-10 bg-emerald-600 text-white text-xs font-bold uppercase tracking-wide hover:bg-emerald-700 transition-colors"
          >
            Simpan ke Master Data
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto p-12 bg-slate-50">
        <div class="max-w-2xl mx-auto space-y-12">
          <div v-for="(section, sIdx) in generatedSchema" :key="sIdx">
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-6 border-b border-slate-200 pb-2">{{ section.section }}</h3>
            <div class="grid grid-cols-2 gap-6">
              <div v-for="field in section.fields" :key="field.key" class="col-span-2">
                <label class="block text-xs font-medium text-slate-600 mb-2">{{ field.label }}</label>
                
                <textarea v-if="field.type === 'textarea'" class="w-full h-20 border border-slate-200 bg-white rounded-sm" disabled></textarea>
                <div v-else-if="field.type === 'signature'" class="w-full h-24 border-2 border-dashed border-slate-200 bg-white rounded-sm flex items-center justify-center text-xs text-slate-400" disabled>Area Tanda Tangan</div>
                <div v-else class="h-9 border border-slate-200 bg-white rounded-sm w-full" disabled></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="w-80 border-l border-slate-200 bg-slate-50 p-6 overflow-y-auto">
        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4">Struktur JSON</h3>
        <pre class="text-[10px] text-slate-600 font-mono overflow-x-auto bg-white p-4 border border-slate-200 rounded">{{ JSON.stringify(generatedSchema, null, 2) }}</pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const fileInputRef = ref(null);
const selectedFile = ref(null);
const isLoading = ref(false);
// PERBAIKAN: Pastikan ini array kosong
const generatedSchema = ref([]);

const triggerFileInput = () => fileInputRef.value.click();
const handleFileChange = (e) => selectedFile.value = e.target.files[0];

const uploadAndGenerateForm = async () => {
  isLoading.value = true;
  const formData = new FormData();
  formData.append('rm_file', selectedFile.value);
  
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token'); 
  
  try {
    const res = await axios.post('http://127.0.0.1:8000/api/v1/rekam-medis/templates/auto-generate', formData, {
      headers: { Authorization: `Bearer ${token}` }
    });
    
    // 1. Cek di Console Browser (F12 -> Console) untuk melihat isi asli otak AI
    console.log("Tangkapan Asli dari Gemini:", res.data);

    let aiData = res.data.data;

    // 2. PEMBERSIH MARKDOWN: Jika AI membalas pakai teks ```json ... ```
    if (typeof aiData === 'string') {
      try {
        // Buang markdown dan parse paksa jadi JSON
        const cleanString = aiData.replace(/```json/g, '').replace(/```/g, '').trim();
        aiData = JSON.parse(cleanString);
      } catch (err) {
        console.error("Gagal melakukan parse pada string AI:", err);
      }
    }

    // 3. PEMBUNGKUS ARRAY: Jika AI membalas bentuk Object { }, kita paksa jadi Array [ { } ]
    if (aiData && typeof aiData === 'object' && !Array.isArray(aiData)) {
      aiData = [aiData];
    }

    // Masukkan ke state Vue
    generatedSchema.value = aiData || [];
    
  } catch (e) {
    alert('Error: ' + (e.response?.data?.message || e.message));
    console.error("Detail Error Axios:", e);
  } finally {
    isLoading.value = false;
  }
};

const saveSchemaToDatabase = async () => {
  if (!generatedSchema.value?.length) return;

  const defaultName = selectedFile.value?.name.split('.')[0] || "Formulir Baru";
  const namaForm = prompt("Masukkan Nama Formulir untuk disimpan ke Master Data:", defaultName);
  
  if (!namaForm) return; 

  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  try {
    const res = await axios.post('http://127.0.0.1:8000/api/v1/rekam-medis/templates/store', {
      nama_rm: namaForm,
      schema: generatedSchema.value
    }, {
      headers: { Authorization: `Bearer ${token}` }
    });

    alert('Berhasil! Template RME disimpan ke database.');
    console.log('Saved:', res.data);
  } catch (e) {
    alert('Gagal menyimpan: ' + (e.response?.data?.message || e.message));
  }
};
</script>