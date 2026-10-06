import { createRoot } from '@wordpress/element';
import Editor from './Editor';
import './editor.css';

const root = document.getElementById('cpub-editor-root');
if (root) {
  createRoot(root).render(<Editor />);
}
