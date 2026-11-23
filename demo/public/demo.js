// Two small behaviours, no framework: the tag list of the product form, and keeping the save history
// in sync on the demo page whenever one of the editors reloads (that is, after every submit).

document.addEventListener('DOMContentLoaded', () => {
    const tags = document.querySelector('[data-tags]');
    if (tags) {
        const list = tags.querySelector('[data-tags-list]');
        let index = Number(tags.dataset.index);

        tags.querySelector('[data-tags-add]').addEventListener('click', () => {
            const item = document.createElement('li');
            item.innerHTML = tags.dataset.prototype.replace(/__name__/g, String(index++));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-link';
            remove.dataset.tagsRemove = '';
            remove.textContent = tags.dataset.removeLabel;
            item.append(remove);
            list.append(item);
            item.querySelector('input')?.focus();
        });

        list.addEventListener('click', (event) => {
            if (event.target.matches('[data-tags-remove]')) {
                event.target.closest('li').remove();
                tags.querySelector('[data-tags-add]').focus();
            }
        });
    }

    const history = document.querySelector('[data-history]');
    document.querySelectorAll('[data-editor]').forEach((editor) => {
        editor.addEventListener('load', () => history?.contentWindow?.location.reload());
    });
});
