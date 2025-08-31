# 🔄 Manual Polling Guide

## New Manual Polling Feature

This document explains how to use the new manual polling functionality added to resolve polling system issues.

## 🎯 Quick Fix

If your polling system is not working:

1. **Go to**: WordPress Admin → Settings → HIC Diagnostics
2. **Find**: "🔄 Controllo Manuale Polling" section
3. **Click**: "Forza Polling Ora" button (blue button)
4. **Wait**: For the results to appear
5. **Check**: The detailed diagnostics below

## 📋 Available Options

### "Forza Polling Ora" (Force Polling Now)
- **When to use**: When polling is completely stuck or not working
- **What it does**: Immediately executes polling, bypassing any locks
- **Best for**: Emergency situations and first-time testing

### "Test Polling (con lock)" (Test Polling with Lock)
- **When to use**: To test normal polling operation
- **What it does**: Executes polling normally, respecting existing locks
- **Best for**: Regular testing and troubleshooting

## 🔍 Understanding the Diagnostics

### Conditions Section
Each condition must be ✅ (green checkmark) for automatic polling to work:

- **Reliable Polling Enabled**: Must be turned on in settings
- **Connection Type Api**: Must be set to "API Polling" (not webhook)
- **Api Url Configured**: Must have valid Hotel in Cloud API URL
- **Has Credentials**: Must have either Basic Auth OR API Key
- **Basic Auth Complete**: Must have Property ID + Email + Password
- **Api Key Configured**: Alternative to Basic Auth (legacy method)

### What to Check If Polling Fails

1. **❌ Reliable Polling Enabled = No**
   - **Fix**: Go to HIC Settings → Enable "Sistema Polling Affidabile"

2. **❌ Connection Type Api = No**
   - **Fix**: Go to HIC Settings → Set "Tipo Connessione" to "API Polling"

3. **❌ Api Url Configured = No**
   - **Fix**: Go to HIC Settings → Enter valid "API URL" from Hotel in Cloud

4. **❌ Has Credentials = No**
   - **Fix**: Configure either:
     - Property ID + Email + Password (recommended)
     - OR API Key (legacy method)

### Lock Status
- **🔓 No**: Normal, polling can run
- **🔒 Sì**: Another polling process is active
  - If lock is older than 5 minutes, use "Forza Polling Ora"

## 🚨 Troubleshooting Common Issues

### Issue: "Il sistema di polling non è attivo!"
**Solution**: Check all conditions in diagnostics. Usually missing credentials or wrong connection type.

### Issue: Polling works manually but not automatically
**Solution**: 
1. Check if Heartbeat API is working (WordPress feature)
2. Verify all conditions are ✅ in diagnostics
3. Use "Riavvia Sistema Interno" button

### Issue: Lock is stuck (active for >5 minutes)
**Solution**: Use "Forza Polling Ora" to clear the lock and restart polling

## 💡 Best Practices

1. **Test First**: Always use "Test Polling" before going live
2. **Check Logs**: Monitor the logs section for detailed error messages
3. **Regular Checks**: Use "Forza Polling Ora" periodically to ensure system health
4. **Monitor Conditions**: Keep an eye on the diagnostics - all should be ✅

## 📞 Getting Help

If polling still doesn't work after following this guide:

1. Take a screenshot of the diagnostic results
2. Check the logs section for error messages
3. Note which conditions show ❌ in diagnostics
4. Contact support with this information

The manual polling feature ensures you can always trigger polling immediately, even if the automatic system has issues.