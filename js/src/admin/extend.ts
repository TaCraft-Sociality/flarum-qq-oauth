import type Mithril from 'mithril';

import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

import { ICON_PATH_SETTING, ICON_URL_ATTRIBUTE, SETTINGS_PREFIX } from '../common/constants';

function trans(key: string, params?: Record<string, unknown>): Mithril.Children {
  const id = `tacraft-sociality-qq-oauth.admin.settings.${key}`;

  return params === undefined ? app.translator.trans(id) : app.translator.trans(id, params);
}

function callbackUrl(): string {
  const baseUrl = String(app.forum.attribute<string>('baseUrl') ?? '').replace(/\/+$/, '');

  return `${baseUrl}/auth/qq`;
}

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: `${SETTINGS_PREFIX}app_id`,
      type: 'text',
      label: trans('app_id_label'),
      help: trans('app_id_help', { url: callbackUrl() }),
      placeholder: '100000000',
    }))
    .setting(() => ({
      setting: `${SETTINGS_PREFIX}app_secret`,
      type: 'text',
      label: trans('app_secret_label'),
      help: trans('app_secret_help'),
    }))
    .setting(() => ({
      setting: `${SETTINGS_PREFIX}sync_avatar`,
      type: 'bool',
      label: trans('sync_avatar_label'),
      help: trans('sync_avatar_help'),
    }))
    .setting(() => ({
      setting: ICON_PATH_SETTING,
      type: 'image-upload',
      name: 'qq-oauth-icon',
      routePath: 'qq-oauth/icon',
      value: '',
      url: () => app.forum.attribute<string | null>(ICON_URL_ATTRIBUTE) ?? '',
      label: trans('icon_label'),
      help: trans('icon_help'),
    })),
];
