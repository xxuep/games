// index.js
const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');

const app = express();
app.use(cors());
app.use(express.json());

const API_KEY = process.env.CLOUDCONVERT_API_KEY;
if (!API_KEY) {
  console.error("Set CLOUDCONVERT_API_KEY environment variable");
  process.exit(1);
}

app.post('/convert', async (req, res) => {
  try {
    const { youtubeUrl } = req.body;
    if (!youtubeUrl) return res.status(400).json({ error: "Missing youtubeUrl" });

    // Create conversion job
    const jobRes = await fetch('https://api.cloudconvert.com/v2/jobs', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${API_KEY}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        tasks: {
          'import-youtube': {
            operation: 'import/youtube',
            youtube_link: youtubeUrl
          },
          'convert-to-mp4': {
            operation: 'convert',
            input: ['import-youtube'],
            output_format: 'mp4',
            video_codec: 'h264',
            audio_codec: 'aac'
          },
          'export-url': {
            operation: 'export/url',
            input: ['convert-to-mp4'],
            inline: false,
            archive_multiple_files: false
          }
        }
      })
    });
    if (!jobRes.ok) {
      const errorData = await jobRes.json();
      return res.status(500).json({ error: errorData.message || 'Failed to create job' });
    }
    const jobData = await jobRes.json();
    const jobId = jobData.data.id;

    // Poll for completion
    let jobStatus = '';
    let downloadUrl = '';
    while (true) {
      await new Promise(r => setTimeout(r, 3000));
      const statusRes = await fetch(`https://api.cloudconvert.com/v2/jobs/${jobId}`, {
        headers: { Authorization: `Bearer ${API_KEY}` }
      });
      if (!statusRes.ok) {
        return res.status(500).json({ error: 'Failed to get job status' });
      }
      const statusData = await statusRes.json();
      jobStatus = statusData.data.status;

      if (jobStatus === 'error') {
        return res.status(500).json({ error: 'Conversion error' });
      }
      if (jobStatus === 'finished') {
        const exportTask = Object.values(statusData.data.tasks).find(t => t.operation === 'export/url');
        if (exportTask && exportTask.result && exportTask.result.files.length > 0) {
          downloadUrl = exportTask.result.files[0].url;
        }
        break;
      }
    }

    if (!downloadUrl) return res.status(500).json({ error: 'Failed to get download URL' });

    return res.json({ downloadUrl });

  } catch (err) {
    return res.status(500).json({ error: err.message || 'Unknown error' });
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`API running on port ${PORT}`));
