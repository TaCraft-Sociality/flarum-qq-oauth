import type Mithril from 'mithril';
import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type IExtender from 'flarum/common/extenders/IExtender';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';
import LogInButtons from 'flarum/forum/components/LogInButtons';

import { AUTH_PATH, ENABLED_ATTRIBUTE } from '../common/constants';

import qqIcon from './components/QQIcon';
import { handleAuthRedirect } from './handleAuthRedirect';

/**
 * Attribute set on the SignUpModal attrs by handleAuthRedirect() when the
 * registration was started through QQ. QQ accounts get a virtual email address
 * generated on the server, so the modal must not show an email field at all.
 */
const QQ_SIGNUP_ATTRIBUTE = 'qqOAuth';

function isQqSignup(attrs: Record<string, any> | null | undefined): boolean {
  return Boolean(attrs?.[QQ_SIGNUP_ATTRIBUTE]);
}

function logInUrl(): string {
  const baseUrl = String(app.forum.attribute<string>('baseUrl') ?? '').replace(/\/+$/, '');
  const current = `${window.location.pathname}${window.location.search}`;
  const returnTo = current.startsWith(AUTH_PATH) ? '/' : current;
  return `${baseUrl}${AUTH_PATH}?returnTo=${encodeURIComponent(returnTo)}`;
}

function logInButton(): Mithril.Children {
  const label = app.translator.trans('tacraft-sociality-qq-oauth.forum.log_in_with');
  return (
    <a className="Button LogInButton LogInButton--QQ" href={logInUrl()}>
      {qqIcon('Button-icon QQOAuth-icon')}
      <span className="Button-label">
        <span className="Button-labelText">{label}</span>
      </span>
    </a>
  );
}

const qqOAuthExtender: IExtender = {
  extend() {
    extend(LogInButtons.prototype, 'items', function (items) {
      if (!app.forum.attribute<boolean>(ENABLED_ATTRIBUTE)) return;
      items.add('qq-oauth', logInButton());
    });

    // Runs before the core oninit, so the email stream starts out empty.
    override('flarum/forum/components/SignUpModal', 'oninit', function (this: any, original: any, vnode: any) {
      if (isQqSignup(vnode?.attrs)) {
        vnode.attrs.email = null;
        vnode.attrs.provided = (Array.isArray(vnode.attrs.provided) ? vnode.attrs.provided : []).filter(
          (field: string) => field !== 'email'
        );
      }

      return original(vnode);
    });

    extend('flarum/forum/components/SignUpModal', 'fields', function (this: any, items: ItemList<Mithril.Children>) {
      if (isQqSignup(this.attrs)) {
        items.remove('email');
      }
    });

    // The email field is gone, so it must not be submitted either: the server
    // generates the virtual address when the account is created.
    override('flarum/forum/components/SignUpModal', 'submitData', function (this: any, original: any) {
      const data = original();

      if (isQqSignup(this.attrs)) {
        delete data.email;
      }

      return data;
    });

    extend('flarum/forum/components/UserSecurityPage', 'settingsItems', function (items: ItemList<Mithril.Children>) {
      const user = (this as unknown as { user?: User }).user;

      if (!user || user.id() !== app.session.user?.id() || !app.forum.attribute<boolean>(ENABLED_ATTRIBUTE)) {
        return;
      }

      // Every account that can sign in through QQ has a system generated email
      // address, exactly like the GitHub/Microsoft/Blessing extensions, so all
      // of them show the same notice. They share the item key, meaning an
      // account bound to several providers only shows one notice.
      if (user.attribute<boolean>('hasQqBinding') === true) {
        items.add(
          'oauthAccountNotice',
          (
            <div className="Alert Alert--warning" style={{ marginBottom: '20px' }}>
              {'本账号的登录方式由注册时使用的第三方平台（QQ、GitHub、微软、Tcsskin）决定，系统不支持换绑或解绑。如需更换登录方式，请联系管理员。'}
            </div>
          ),
          101
        );
      }
    });

    // Accounts that can log in through QQ have their credentials managed by the
    // provider: hide both controls from the account section of the settings
    // page, exactly like the GitHub/Microsoft/Blessing extensions do.
    extend('flarum/forum/components/SettingsPage', 'accountItems', function (items: ItemList<Mithril.Children>) {
      const user = app.session.user;

      if (!user || user.attribute<boolean>('hasQqBinding') !== true) {
        return;
      }

      items.remove('changePassword');
      items.remove('changeEmail');
    });

    // Email notifications are disabled site-wide: notifications are delivered
    // in-site only, so drop the "email" column from the grid entirely.
    extend('flarum/forum/components/NotificationGrid', 'notificationMethods', function (this: any, items: ItemList<any>) {
      items.remove('email');
    });

    app.beforeMount(() => {
      void handleAuthRedirect();
    });
  },
};

export default [qqOAuthExtender];
